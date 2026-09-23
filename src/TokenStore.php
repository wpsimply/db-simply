<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * One-time sign-on tokens.
 *
 * The control panel drops a file named after a random token into the token
 * directory, holding the credentials the session is allowed to use, and sends
 * the user to sso.php?token=<token>. The token is spent on first use and
 * expires after a short window either way, so a URL that leaks through
 * history or a referrer is worthless by the time anyone else holds it.
 *
 * The server the session connects to is never part of the token: it comes
 * from the configuration alone.
 *
 * File contents (JSON):
 *   user      required  database user to sign in as
 *   password  optional  its password
 *   database  optional  database to open straight away
 *   label     optional  name shown in the header
 *   readonly  optional  true to allow reading only
 */
final class TokenStore
{
    public function __construct(private readonly string $directory, private readonly int $ttl) {}

    /**
     * Spend a token and return the session it grants.
     *
     * @return array{user: string, password: ?string, database: ?string, label: string, readonly: bool}
     */
    public function consume(string $token): array
    {
        if (preg_match('/^[a-f0-9]{32,128}$/', $token) !== 1) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        $file = $this->directory.'/'.$token;
        $claimed = $file.'.'.bin2hex(random_bytes(4)).'.claimed';

        // Renaming is atomic: of two requests racing for one token only one
        // can move the file, so a token cannot be spent twice.
        if (! @rename($file, $claimed)) {
            throw new UserError('This sign-on link is invalid or has already been used.', 403);
        }

        try {
            $age = time() - (int) filemtime($claimed);
            $payload = json_decode((string) file_get_contents($claimed), true);
        } finally {
            @unlink($claimed);
        }

        if ($age > $this->ttl) {
            throw new UserError('This sign-on link has expired.', 403);
        }

        if (! is_array($payload)) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        return self::validate($payload);
    }

    /**
     * Remove tokens nobody came back for.
     */
    public function prune(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_file($file) && time() - (int) filemtime($file) > $this->ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{user: string, password: ?string, database: ?string, label: string, readonly: bool}
     */
    public static function validate(array $payload): array
    {
        $user = $payload['user'] ?? null;

        if (! is_string($user) || $user === '' || strlen($user) > 128) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        $optional = static fn (string $key): ?string => isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== ''
            ? $payload[$key]
            : null;

        $database = $optional('database');

        if ($database !== null && ! Identifier::isValid($database)) {
            $database = null;
        }

        return [
            'user' => $user,
            'password' => isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : null,
            'database' => $database,
            'label' => mb_substr($optional('label') ?? $user, 0, 120),
            'readonly' => ($payload['readonly'] ?? false) === true,
        ];
    }
}
