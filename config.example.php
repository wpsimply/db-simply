<?php

/*
 * Copy this file to config.php and adjust it. Every key is optional: anything
 * left out falls back to the defaults in src/Config.php.
 */

return [
    /*
     * The database server every session connects to. A sign-on token only
     * ever supplies the user and password; where the connection goes is set
     * here and nowhere else.
     */
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        // 'socket' => '/run/mysqld/mysqld.sock',
        'timeout' => 5,
        'read_timeout' => 300,
        'ssl' => false,
        // 'ssl_ca' => '/etc/ssl/db/server-cert.pem',
        'ssl_verify' => true,
    ],

    // Never listed or opened, even when the signed-in user can see them.
    'hidden_databases' => ['information_schema', 'performance_schema', 'mysql', 'sys'],

    /*
     * Where sign-on tokens are dropped and how long one stays valid. The
     * directory must be writable by the PHP-FPM pool and nobody else.
     */
    'sso' => [
        'token_dir' => __DIR__.'/storage/sso-tokens',
        'token_ttl' => 60,
    ],

    /*
     * Imports: where uploads are kept while they run (writable by the pool
     * and nobody else), the largest file accepted, and the seconds each
     * request spends running statements before it reports progress.
     */
    'import' => [
        'dir' => __DIR__.'/storage/imports',
        'max_bytes' => 2147483648,
        'budget' => 20,
    ],

    'session' => [
        'save_path' => __DIR__.'/storage/sessions',
        'name' => 'DbSimplySession',
        'secure' => true,
        'idle_timeout' => 1800,
        'lifetime' => 28800,
    ],

    /*
     * Shown on the signed-out page, so a user whose session ended knows where
     * to go to open a new one.
     */
    'panel_url' => null,

    'title' => 'DB Simply',

    'limits' => [
        // Rows per page when browsing a table.
        'page_size' => 50,
        // Bytes of a value shown in a grid cell before it is cut off.
        'cell_preview' => 1024,
        // Bytes of a single value shown when it is opened.
        'value_preview' => 1048576,
        // Rows a query in the SQL console returns at most.
        'query_rows' => 1000,
        // Tables estimated to hold more rows than this show the estimate
        // instead of being counted, unless a filter is applied.
        'exact_count' => 100000,
    ],

    // Decode PHP-serialized values for display. Classes are never instantiated.
    'decode_serialized' => true,
];
