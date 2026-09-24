<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use DbSimply\Catalog;
use DbSimply\Codec;
use DbSimply\Config;
use DbSimply\Env;
use DbSimply\Formatter;
use DbSimply\Identifier;
use DbSimply\Serialized;
use DbSimply\Session;
use DbSimply\TokenStore;
use DbSimply\UserError;
use RuntimeException;

/**
 * Everything that needs no database.
 */
final class UnitTest extends TestCase
{
    public function testCellsCarryTextBinaryNullAndTruncation(): void
    {
        self::assertSame(null, Codec::cell(null));
        self::assertSame('plain', Codec::cell('plain'));
        self::assertSame(['$b64' => base64_encode("\x00\xff")], Codec::cell("\x00\xff"));
        self::assertSame(['$t' => 'abc', 'len' => 10], Codec::cell('abc', 10));
        self::assertSame(['$b64' => base64_encode("\x00a"), 'len' => 10], Codec::cell("\x00a", 10));

        // A cut through "é" (two bytes) stays text, minus the half character.
        [$preview, $length] = Codec::preview('aé', 2);
        self::assertSame(['$t' => 'a', 'len' => 3], Codec::cell($preview, $length));
    }

    public function testDecodeRefusesTruncatedValues(): void
    {
        self::assertSame("\x00\xff", Codec::decode(['$b64' => base64_encode("\x00\xff")]));
        self::assertSame('12', Codec::decode(12));
        self::assertSame(null, Codec::decode(null));
        self::assertThrows(UserError::class, fn () => Codec::decode(['$t' => 'abc', 'len' => 10]));
        self::assertThrows(UserError::class, fn () => Codec::decode(['$b64' => 'YQ==', 'len' => 10]));
    }

    public function testIdentifiersAreQuotedWithDoubledBackticks(): void
    {
        self::assertSame('`wp_posts`', Identifier::quote('wp_posts'));
        self::assertSame('`a``b`', Identifier::quote('a`b'));
        self::assertSame('`db`.`t`', Identifier::qualified('db', 't'));
        self::assertThrows(UserError::class, fn () => Identifier::quote(''));
        self::assertThrows(UserError::class, fn () => Identifier::quote("a\0b"));
        self::assertThrows(UserError::class, fn () => Identifier::quote(str_repeat('x', 65)));
    }

    public function testEnvironmentOverridesDotEnvAndListsAreSplit(): void
    {
        $dir = self::tempDir();
        file_put_contents($dir.'/.env', "DB_SIMPLY_DB_PORT=3307\nDB_SIMPLY_DB_HOST=file-host\nDB_SIMPLY_TOKEN_DIR=\nDB_SIMPLY_SESSION_SECURE=false\nDB_SIMPLY_HIDDEN_DATABASES=mysql, sys\n");

        $config = Config::fromArray($dir, Env::overrides($dir.'/.env', ['DB_SIMPLY_DB_HOST' => 'env-host']));

        self::assertSame('env-host', $config->get('db.host'));
        self::assertSame(3307, $config->get('db.port'));
        self::assertSame(false, $config->get('session.secure'));
        self::assertSame(['mysql', 'sys'], $config->get('hidden_databases'));
        self::assertSame($dir.'/storage/sso-tokens', $config->get('sso.token_dir'));
    }

    public function testHomeComesFromTheRealEnvironmentOnly(): void
    {
        $dir = self::tempDir();

        self::assertSame('/app', Env::home('/app', []));
        self::assertSame(realpath($dir), Env::home('/app', ['DB_SIMPLY_HOME' => $dir.'/']));
        self::assertThrows(RuntimeException::class, fn () => Env::home('/app', ['DB_SIMPLY_HOME' => $dir.'/missing']), 'not a directory');
    }

    public function testTokenIsSpentOnFirstUse(): void
    {
        $dir = self::tempDir();
        $token = bin2hex(random_bytes(32));
        file_put_contents($dir.'/'.$token, json_encode(['user' => 'acct', 'password' => 'secret', 'database' => 'acct_shop', 'label' => 'Acme', 'readonly' => true]));

        $store = new TokenStore($dir, 60);

        self::assertSame(
            ['user' => 'acct', 'password' => 'secret', 'database' => 'acct_shop', 'label' => 'Acme', 'readonly' => true],
            $store->consume($token),
        );
        self::assertThrows(UserError::class, fn () => $store->consume($token), 'already been used');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testTokenNeedsAUserAndDefaultsTheRest(): void
    {
        self::assertSame(
            ['user' => 'acct', 'password' => null, 'database' => null, 'label' => 'acct', 'readonly' => false],
            TokenStore::validate(['user' => 'acct', 'readonly' => 'yes', 'host' => 'elsewhere']),
        );
        self::assertThrows(UserError::class, fn () => TokenStore::validate(['password' => 'x']));
        self::assertThrows(UserError::class, fn () => (new TokenStore(self::tempDir(), 60))->consume('../'.str_repeat('a', 40)));
    }

    public function testABoundTokenIsSpentOnlyByTheBrowserHoldingItsProof(): void
    {
        $dir = self::tempDir();
        $proof = bin2hex(random_bytes(32));
        $issue = static function (array $payload) use ($dir): string {
            $token = bin2hex(random_bytes(32));
            file_put_contents($dir.'/'.$token, json_encode(['user' => 'acct', ...$payload]));

            return $token;
        };

        $store = new TokenStore($dir, 60);

        self::assertSame('acct', $store->consume($issue(['binding' => TokenStore::binding($proof)]), $proof)['user']);

        // Someone else's link, opened in a browser without the proof, or with another one.
        $foreign = $issue(['binding' => TokenStore::binding($proof)]);
        self::assertThrows(UserError::class, fn () => $store->consume($foreign), 'another browser');
        self::assertThrows(UserError::class, fn () => $store->consume($foreign, $proof), 'already been used');
        self::assertThrows(UserError::class, fn () => $store->consume($issue(['binding' => TokenStore::binding($proof)]), bin2hex(random_bytes(32))), 'another browser');
        self::assertThrows(UserError::class, fn () => $store->consume($issue(['binding' => ['x']]), $proof), 'another browser');

        // Unbound tokens work until binding is required.
        self::assertSame('acct', $store->consume($issue([]))['user']);
        self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60, true))->consume($issue([]), $proof), 'not issued to a browser');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testSignOnStartsWithAProofOnlyThisBrowserHolds(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => false]]));

        self::assertSame(null, $session->signOnProof());

        $binding = $session->startSignOn();
        $proof = $session->signOnProof();

        self::assertTrue($proof !== null && TokenStore::binding($proof) === $binding);
        self::assertTrue(! str_contains($binding, (string) $proof), 'The panel sees the hash, never the proof.');

        $_COOKIE['DbSimplySessionSignOn'] = 'not a proof';
        self::assertSame(null, $session->signOnProof());
        unset($_COOKIE['DbSimplySessionSignOn']);
    }

    public function testExpiredTokenIsRejectedAndRemoved(): void
    {
        $dir = self::tempDir();
        $token = bin2hex(random_bytes(32));
        file_put_contents($dir.'/'.$token, json_encode(['user' => 'acct']));
        touch($dir.'/'.$token, time() - 120);

        self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60))->consume($token), 'expired');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testSessionKeepsThePasswordEncryptedUnderTheCookieKey(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => false]]));

        $session->signIn(['user' => 'acct', 'password' => 'hunter2', 'database' => null, 'label' => 'Acme', 'readonly' => false]);

        self::assertSame(null, $_SESSION['grant']['password']);
        self::assertTrue(! str_contains(serialize($_SESSION), 'hunter2'), 'The password must not be stored in the session in the clear.');
        self::assertSame('hunter2', $session->grant()['password'] ?? null);

        // Without the key cookie, the session is worthless.
        unset($_COOKIE['DbSimplySessionKey']);
        self::assertSame(null, $session->grant());

        session_write_close();
    }

    public function testSecureSessionCookiesCannotBeSetFromASiblingSubdomain(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => true]]));

        $session->signIn(['user' => 'acct', 'password' => 'hunter2', 'database' => null, 'label' => 'Acme', 'readonly' => false]);

        self::assertSame('__Host-DbSimplySession', session_name());
        self::assertTrue(isset($_COOKIE['__Host-DbSimplySessionKey']));
        self::assertSame('', session_get_cookie_params()['domain']);

        session_write_close();
        session_name('DbSimplySession');
    }

    public function testRowKeyPrefersThePrimaryKeyThenANotNullUniqueIndex(): void
    {
        $columns = [
            ['name' => 'id', 'nullable' => false],
            ['name' => 'email', 'nullable' => false],
            ['name' => 'nick', 'nullable' => true],
        ];

        $unique = static fn (string $name, array $columns, bool $primary = false): array => [
            'unique' => true,
            'primary' => $primary,
            'columns' => array_map(static fn (string $column): array => ['name' => $column, 'subPart' => null], $columns),
        ];

        self::assertSame(['id'], Catalog::rowKey($columns, [$unique('email', ['email']), $unique('PRIMARY', ['id'], true)]));
        self::assertSame(['email'], Catalog::rowKey($columns, [$unique('nick', ['nick']), $unique('email', ['email'])]));
        self::assertSame(null, Catalog::rowKey($columns, [$unique('nick', ['nick'])]));
        self::assertSame(null, Catalog::rowKey($columns, [
            ['unique' => true, 'primary' => false, 'columns' => [['name' => 'email', 'subPart' => 10]]],
        ]));
    }

    public function testFormatterDecodesSerializedWordPressOptionsWithoutInstantiatingClasses(): void
    {
        $formatter = new Formatter;
        $described = $formatter->describe(serialize(['siteurl' => 'https://example.com', 'widget' => new \ArrayObject([1])]));

        self::assertSame('serialized', $described['format']);
        self::assertTrue(str_contains((string) $described['pretty'], '"__class": "ArrayObject"'));
        self::assertSame('json', $formatter->describe('{"a":1}')['format']);
    }

    public function testSerializedValuesAreReadWithoutUnserialize(): void
    {
        self::assertSame(
            [['__class' => 'Plugin_Settings', 'public' => 1, 'kept' => [1.5, true], 'secret' => 'a\x00b']],
            Serialized::decode('O:15:"Plugin_Settings":3:{s:6:"public";i:1;s:7:"'."\0*\0".'kept";a:2:{i:0;d:1.5;i:1;b:1;}s:23:"'."\0Plugin_Settings\0".'secret";s:3:"a'."\0".'b";}'),
        );
        self::assertSame([false], Serialized::decode('b:0;'));
        self::assertSame([null], Serialized::decode('N;'));
        self::assertSame([['a', '(reference to value 2)']], Serialized::decode('a:2:{i:0;s:1:"a";i:1;R:2;}'));
        self::assertSame([['__class' => 'Legacy', '__data' => 'x:1']], Serialized::decode('C:6:"Legacy":3:{x:1}'));

        self::assertSame(null, Serialized::decode('s:5:"abc";'), 'A wrong length is not serialized data.');
        self::assertSame(null, Serialized::decode('a:1:{i:0;N;}trailing'));
        self::assertSame(null, Serialized::decode(str_repeat('a:1:{i:0;', 100).'N;'.str_repeat('}', 100)), 'Nesting is limited.');
    }
}
