<?php

declare(strict_types=1);

use DbAdmin\Catalog;
use DbAdmin\Connection;
use DbAdmin\Download;
use DbAdmin\Export;
use DbAdmin\Rows;
use DbAdmin\Session;
use DbAdmin\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

db_admin_headers();

/**
 * Answer a failure that happened before anything was sent: a short page with
 * the reason and a way back, since the browser navigated here to download.
 */
$fail = static function (string $message, int $status): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>Export failed</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="signed-out"><div class="card">',
        '<h1>Export failed</h1><p>', $e($message), '</p><a class="button primary" href="./">Back to DB Admin</a></div></main></body></html>';
    exit;
};

$session = new Session($config);
$grant = $session->grant();

if ($grant === null) {
    $fail('Your session has ended. Open DB Admin again from your control panel.', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ! $session->verifyCsrf($_POST['csrf'] ?? null)) {
    $fail('Your session token is out of date. Reload the page and try again.', 419);
}

$session->release();
set_time_limit(0);

$download = null;

try {
    $client = (new Connection($config))->open($grant);
    $hidden = $config->get('hidden_databases');
    $catalog = new Catalog($client, is_array($hidden) ? array_values(array_filter($hidden, is_string(...))) : []);
    $database = $catalog->database($_POST['db'] ?? null);
    $gzip = ($_POST['gzip'] ?? '') === '1';
    $stamp = gmdate('Ymd-His');

    switch ($_POST['format'] ?? '') {
        case 'sql':
            $tables = json_decode((string) ($_POST['tables'] ?? '[]'), true);

            if (! is_array($tables)) {
                throw new UserError('Invalid table selection.');
            }

            $tables = array_map(static fn (mixed $name): string => $catalog->table($database, $name)['name'], array_values($tables));
            $label = count($tables) === 1 ? $database.'-'.$tables[0] : $database;

            $download = new Download(Download::filename($label.'-'.$stamp, 'sql'), 'application/sql; charset=utf-8', $gzip);
            (new Export($client, $catalog, $download->write(...)))->sql(
                $database,
                $tables,
                ($_POST['structure'] ?? '') === '1',
                ($_POST['data'] ?? '') === '1',
            );
            break;

        case 'csv':
            $sql = trim((string) ($_POST['sql'] ?? ''));

            if ($sql !== '') {
                $download = new Download(Download::filename($database.'-query-'.$stamp, 'csv'), 'text/csv; charset=utf-8', $gzip);
                (new Export($client, $catalog, $download->write(...)))->csvQuery($database, $sql);
                break;
            }

            $table = $catalog->table($database, $_POST['table'] ?? null)['name'];
            $filters = json_decode((string) ($_POST['filters'] ?? '[]'), true);
            $rows = new Rows($client, $catalog, $config->int('limits.cell_preview'), $config->int('limits.value_preview'), $config->int('limits.exact_count'));
            $where = $rows->filterCondition($database, $table, is_array($filters) ? $filters : [], (string) ($_POST['q'] ?? ''));

            $download = new Download(Download::filename($table.'-'.$stamp, 'csv'), 'text/csv; charset=utf-8', $gzip);
            (new Export($client, $catalog, $download->write(...)))->csvTable($database, $table, $where);
            break;

        default:
            throw new UserError('Unknown export format.');
    }

    $download->finish();
} catch (Throwable $e) {
    $message = $e instanceof UserError ? $e->getMessage() : 'Something went wrong. The error has been logged.';

    if (! $e instanceof UserError) {
        error_log('db-admin: export failed: '.$e);
    }

    if ($download === null || ! $download->started()) {
        $fail($message, $e instanceof UserError ? $e->status : 500);
    }

    // Too late for an error page: say so at the end of the file, where
    // whoever opens it will see the dump is incomplete.
    $download->write("\n-- EXPORT FAILED: ".str_replace(["\r", "\n"], ' ', $message)."\n");
    $download->finish();
}
