<?php

declare(strict_types=1);

namespace DbAdmin;

use SodiumException;

/**
 * The signed-in session: the credentials it may use, and its CSRF token.
 *
 * The credentials live only in the server-side session, and nothing the
 * browser sends can change which server or user a request connects as. The
 * password is kept encrypted there, under a key that lives only in a cookie
 * of its own: the session file alone, read off disk or out of a backup, does
 * not give the password away, and neither does the cookie alone.
 */
final class Session
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->config->int('session.lifetime'));

        $savePath = (string) $this->config->get('session.save_path');

        if ($savePath !== '') {
            session_save_path($savePath);
        }

        session_name((string) $this->config->get('session.name'));
        session_set_cookie_params(['lifetime' => 0, ...$this->cookieOptions()]);

        session_start();
    }

    /**
     * Replace whatever session this browser had with one for the given grant.
     *
     * @param  array{user: string, password: ?string, database: ?string, label: string, readonly: bool}  $grant
     */
    public function signIn(array $grant): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);

        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $_SESSION['grant'] = [...$grant, 'password' => null];
        $_SESSION['secret'] = $grant['password'] === null ? null : base64_encode($nonce.sodium_crypto_secretbox($grant['password'], $nonce, $key));
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['created_at'] = time();
        $_SESSION['seen_at'] = time();

        $encodedKey = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->setKeyCookie($encodedKey, 0);
        // The rest of this request reads the grant back with the new key.
        $_COOKIE[$this->keyCookieName()] = $encodedKey;

        sodium_memzero($key);
    }

    /**
     * The current grant, with its password decrypted, or null when signed
     * out or timed out.
     *
     * @return array{user: string, password: ?string, database: ?string, label: string, readonly: bool}|null
     */
    public function grant(): ?array
    {
        $this->start();

        if (! isset($_SESSION['grant'], $_SESSION['created_at'], $_SESSION['seen_at'])) {
            return null;
        }

        $now = time();

        if ($now - $_SESSION['seen_at'] > $this->config->int('session.idle_timeout')
            || $now - $_SESSION['created_at'] > $this->config->int('session.lifetime')) {
            $this->signOut();

            return null;
        }

        $grant = $_SESSION['grant'];

        if ($_SESSION['secret'] !== null) {
            $password = $this->decrypt((string) $_SESSION['secret']);

            // The key cookie is gone or was changed: the session cannot be used.
            if ($password === null) {
                $this->signOut();

                return null;
            }

            $grant['password'] = $password;
        }

        $_SESSION['seen_at'] = $now;

        return $grant;
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $this->csrf();

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Release the session lock so parallel requests from one tab do not queue
     * behind a long query or export.
     */
    public function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function signOut(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();

        if (! headers_sent()) {
            setcookie(session_name(), '', [...$this->cookieOptions(), 'expires' => time() - 3600]);
        }

        $this->setKeyCookie('', time() - 3600);
    }

    private function decrypt(string $secret): ?string
    {
        $encoded = $_COOKIE[$this->keyCookieName()] ?? null;
        $box = base64_decode($secret, true);

        if (! is_string($encoded) || $box === false || strlen($box) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        sodium_memzero($key);

        return $plain === false ? null : $plain;
    }

    private function keyCookieName(): string
    {
        return $this->config->get('session.name').'Key';
    }

    private function setKeyCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->keyCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * Both cookies end with the browser session.
     *
     * @return array{path: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieOptions(): array
    {
        return [
            'path' => '/',
            'secure' => (bool) $this->config->get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
