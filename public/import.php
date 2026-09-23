<?php

declare(strict_types=1);

use DbSimply\Catalog;
use DbSimply\Connection;
use DbSimply\Import;
use DbSimply\Session;
use DbSimply\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

db_simply_headers();
header('Content-Type: application/json; charset=utf-8');

/*
 * The import API, one action per request:
 *
 *   POST ?action=start   {"name", "size", "db"}   begin an upload
 *   POST ?action=chunk&id=…&offset=…              raw bytes: the next chunk
 *   POST ?action=run&id=…                         run statements for a while
 *   POST ?action=skip&id=…                        step past a failed statement
 *   POST ?action=cancel&id=…                      stop and delete the upload
 *   GET  ?action=status&id=…
 *
 * Every POST carries the session's CSRF token in X-CSRF-Token. An import can
 * only be seen or driven by the session that started it.
 */

$respond = static function (mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($status < 400 ? ['data' => $data] : ['error' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$session = new Session($config);
$grant = $session->grant();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? '');

try {
    if ($grant === null) {
        throw new UserError('Your session has ended. Open DB Simply again from your control panel.', 401);
    }

    if ($grant['readonly']) {
        throw new UserError('This session is read-only.', 403);
    }

    if ($action !== 'status' && ($method !== 'POST' || ! $session->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        throw new UserError('Your session token is out of date. Reload the page.', 419);
    }

    // The import is the session's own, and the database user's.
    $import = new Import(
        (string) $config->get('import.dir'),
        hash('sha256', session_id().'|'.$grant['user']),
        $config->int('import.max_bytes'),
    );

    $session->release();

    $connect = static function () use ($config, $grant): array {
        $client = (new Connection($config))->open($grant);
        $hidden = $config->get('hidden_databases');

        return [$client, new Catalog($client, is_array($hidden) ? array_values(array_filter($hidden, is_string(...))) : [])];
    };

    switch ($action) {
        case 'start':
            $body = json_decode((string) file_get_contents('php://input'), true);

            if (! is_array($body)) {
                throw new UserError('Expected a JSON body.', 400);
            }

            [$client, $catalog] = $connect();
            $database = $catalog->database($body['db'] ?? null);
            $client->close();

            $respond($import->start($body['name'] ?? null, $body['size'] ?? null, $database));

        case 'chunk':
            $respond($import->append($_GET['id'] ?? null, $_GET['offset'] ?? null, (string) file_get_contents('php://input')));

        case 'run':
            set_time_limit(0);
            [$client] = $connect();

            $respond($import->run($_GET['id'] ?? null, $client, (float) max(1, $config->int('import.budget'))));

        case 'skip':
            $respond($import->skip($_GET['id'] ?? null));

        case 'cancel':
            $import->cancel($_GET['id'] ?? null);
            $respond(['cancelled' => true]);

        case 'status':
            $respond($import->status($_GET['id'] ?? null));

        default:
            throw new UserError('Unknown action.', 404);
    }
} catch (UserError $e) {
    $respond($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('db-simply: import failed: '.$e);
    $respond('Something went wrong. The error has been logged.', 500);
}
