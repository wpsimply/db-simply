<?php

declare(strict_types=1);

namespace DbAdmin\Tests;

use DbAdmin\Catalog;
use DbAdmin\Codec;
use DbAdmin\Config;
use DbAdmin\Env;
use DbAdmin\Formatter;
use DbAdmin\Identifier;
use DbAdmin\Session;
use DbAdmin\TokenStore;
use DbAdmin\UserError;
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
        file_put_contents($dir.'/.env', "DB_ADMIN_DB_PORT=3307\nDB_ADMIN_DB_HOST=file-host\nDB_ADMIN_TOKEN_DIR=\nDB_ADMIN_SESSION_SECURE=false\nDB_ADMIN_HIDDEN_DATABASES=mysql, sys\n");

        $config = Config::fromArray($dir, Env::overrides($dir.'/.env', ['DB_ADMIN_DB_HOST' => 'env-host']));

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
        self::assertSame(realpath($dir), Env::home('/app', ['DB_ADMIN_HOME' => $dir.'/']));
        self::assertThrows(RuntimeException::class, fn () => Env::home('/app', ['DB_ADMIN_HOME' => $dir.'/missing']), 'not a directory');
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
        unset($_COOKIE['DbAdminSessionKey']);
        self::assertSame(null, $session->grant());

        session_write_close();
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
}
