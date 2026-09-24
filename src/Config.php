<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * The application's configuration: the defaults below, overlaid with whatever
 * the environment and config.php set.
 */
final class Config
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    /**
     * Load the configuration for the application in the given directory.
     *
     * Three layers, each overriding the one before: the defaults below, the
     * DB_SIMPLY_* environment (.env, with the real environment winning), and
     * config.php. Use whichever suits the deployment; most need only one.
     */
    public static function load(string $root): self
    {
        $values = self::merge(self::defaults($root), Env::overrides($root.'/.env'));

        $file = $root.'/config.php';
        $overrides = is_file($file) ? require $file : [];

        return new self(self::merge($values, is_array($overrides) ? $overrides : []));
    }

    /**
     * Build a config from an array, over the defaults. Used by the tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromArray(string $root, array $overrides): self
    {
        return new self(self::merge(self::defaults($root), $overrides));
    }

    /**
     * Read a value by dot-separated path.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function int(string $path): int
    {
        return (int) $this->get($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(string $root): array
    {
        return [
            'db' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'socket' => null,
                'timeout' => 5,
                'read_timeout' => 300,
                'ssl' => false,
                'ssl_ca' => null,
                'ssl_verify' => true,
            ],
            // Never listed or opened, even when the signed-in user can see them.
            'hidden_databases' => ['information_schema', 'performance_schema', 'mysql', 'sys'],
            'sso' => [
                'token_dir' => $root.'/storage/sso-tokens',
                'token_ttl' => 60,
                // Where sso.php?start sends the browser for a bound token.
                'issue_url' => null,
                // Refuse tokens that are not bound to a browser.
                'require_binding' => false,
            ],
            'import' => [
                'dir' => $root.'/storage/imports',
                'max_bytes' => 2147483648,
                'budget' => 20,
            ],
            'session' => [
                'save_path' => $root.'/storage/sessions',
                'name' => 'DbSimplySession',
                'secure' => true,
                'idle_timeout' => 1800,
                'lifetime' => 28800,
            ],
            'panel_url' => null,
            'title' => 'DB Simply',
            'limits' => [
                'page_size' => 50,
                'cell_preview' => 1024,
                'value_preview' => 1048576,
                'query_rows' => 1000,
                'exact_count' => 100000,
            ],
            'decode_serialized' => true,
            // Off: a CSV holds exactly what the database does.
            'csv_escape_formulas' => false,
        ];
    }

    /**
     * Recursively overlay associative arrays; lists and scalars are replaced.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
