<?php

declare(strict_types=1);

namespace DbSimply;

use mysqli;
use mysqli_sql_exception;

/**
 * Opens the database connection a grant allows.
 *
 * Where the connection goes -- host, port or socket, and TLS -- comes from
 * the configuration only. The grant supplies nothing but the credentials, and
 * the server's own privileges decide what those can reach.
 */
final class Connection
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  array{user: string, password: ?string}  $grant
     */
    public function open(array $grant): Client
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();

        if ($mysqli === false) {
            throw new UserError('Could not start a database connection.', 503);
        }

        // LOAD DATA LOCAL INFILE would let a query read files the web server
        // can see -- other sessions' sign-on tokens among them. Nothing here
        // needs it, so it is off whatever php.ini says.
        $mysqli->options(MYSQLI_OPT_LOCAL_INFILE, false);
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, $this->config->int('db.timeout')));
        $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, max(1, $this->config->int('db.read_timeout')));
        $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false);

        $flags = 0;

        if ((bool) $this->config->get('db.ssl')) {
            $ca = $this->config->get('db.ssl_ca');
            $mysqli->ssl_set(null, null, is_string($ca) && $ca !== '' ? $ca : null, null, null);
            $flags |= MYSQLI_CLIENT_SSL;

            if (! (bool) $this->config->get('db.ssl_verify')) {
                $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
        }

        $socket = $this->config->get('db.socket');

        try {
            $mysqli->real_connect(
                is_string($socket) && $socket !== '' ? 'localhost' : (string) $this->config->get('db.host'),
                $grant['user'],
                $grant['password'] ?? '',
                null,
                $this->config->int('db.port'),
                is_string($socket) && $socket !== '' ? $socket : null,
                $flags,
            );

            $mysqli->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            // Access denied: the panel's credentials no longer work, which a
            // fresh sign-on from the panel repairs.
            if ($e->getCode() === 1045) {
                throw new UserError('The database refused these credentials. Open DB Simply again from your control panel.', 401);
            }

            error_log('db-simply: connection failed: '.$e->getMessage());

            throw new UserError('Could not connect to the database server.', 503);
        }

        return new Client($mysqli);
    }
}
