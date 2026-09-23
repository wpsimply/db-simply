<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * The JSON API behind the UI: one action per request.
 *
 * Reads are GET, changes are POST with a JSON body and the session's CSRF
 * token in the X-CSRF-Token header. Every action connects as the user the
 * session was signed in as, and only that user; the database and table a
 * request names travel in its query string, so each tab works where its own
 * URL says, and are checked against the {@see Catalog} before use.
 */
final class Api
{
    private const array READS = ['session', 'databases', 'tables', 'structure', 'rows', 'value', 'row', 'fields', 'design', 'collations', 'objects', 'definition', 'search', 'replace-preview', 'processes'];

    /**
     * Changes a read-only session may not make. (The console checks its
     * statements itself.)
     */
    private const array WRITES = ['insert', 'update', 'delete', 'table', 'schema', 'drop-object', 'replace'];

    private ?Client $client = null;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly Connection $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|list<mixed>
     */
    public function handle(string $method, string $action, array $query, array $body, ?string $csrfToken = null): array
    {
        $grant = $this->session->grant();

        if ($grant === null) {
            throw new UserError('Your session has ended. Open DB Simply again from your control panel.', 401);
        }

        $isRead = in_array($action, self::READS, true);

        if ($isRead && $method !== 'GET') {
            throw new UserError('Method not allowed.', 405);
        }

        if (! $isRead) {
            if ($method !== 'POST') {
                throw new UserError('Method not allowed.', 405);
            }

            if (! $this->session->verifyCsrf($csrfToken)) {
                throw new UserError('Your session token is out of date. Reload the page.', 419);
            }

            if ($grant['readonly'] && in_array($action, self::WRITES, true)) {
                throw new UserError('This session is read-only.', 403);
            }
        }

        $this->session->release();

        try {
            return $this->dispatch($grant, $action, $query, $body);
        } finally {
            $this->client?->close();
            $this->client = null;
        }
    }

    /**
     * @param  array{user: string, password: ?string, database: ?string, label: string, readonly: bool}  $grant
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|list<mixed>
     */
    private function dispatch(array $grant, string $action, array $query, array $body): array
    {
        $catalog = $this->catalog($grant);
        $database = fn (): string => $catalog->database($query['db'] ?? null);
        $table = fn (string $database): string => $catalog->table($database, $query['table'] ?? null)['name'];

        switch ($action) {
            case 'session':
                return $this->sessionPayload($grant, $catalog);

            case 'databases':
                return $catalog->databases();

            case 'tables':
                return $catalog->tables($database());

            case 'structure':
                $db = $database();
                $name = $table($db);

                return [
                    'table' => $catalog->table($db, $name),
                    'columns' => $catalog->columns($db, $name),
                    'indexes' => $catalog->indexes($db, $name),
                    'foreignKeys' => $catalog->foreignKeys($db, $name),
                    'triggers' => $catalog->triggers($db, $name),
                    'create' => $catalog->createStatement($db, $name),
                ];

            case 'rows':
                $db = $database();

                return $this->rows($catalog)->page($db, $table($db), [
                    'offset' => (int) ($query['offset'] ?? 0),
                    'limit' => (int) ($query['limit'] ?? $this->config->int('limits.page_size')),
                    'sort' => isset($query['sort']) && $query['sort'] !== '' ? (string) $query['sort'] : null,
                    'direction' => (string) ($query['dir'] ?? 'asc'),
                    'filters' => $this->jsonParameter($query['filters'] ?? null),
                    'search' => (string) ($query['q'] ?? ''),
                ]);

            case 'value':
                $db = $database();

                return $this->rows($catalog)->value($db, $table($db), $query['column'] ?? null, $this->jsonParameter($query['key'] ?? null));

            case 'fields':
                $db = $database();

                return $this->editor($catalog)->fields($db, $table($db));

            case 'row':
                $db = $database();

                return $this->editor($catalog)->row($db, $table($db), $this->jsonParameter($query['key'] ?? null));

            case 'insert':
                $db = $database();

                return $this->editor($catalog)->insert($db, $table($db), $body['values'] ?? null);

            case 'update':
                $db = $database();

                return $this->editor($catalog)->update($db, $table($db), $body['key'] ?? null, $body['values'] ?? null);

            case 'delete':
                $db = $database();

                return $this->editor($catalog)->delete($db, $table($db), $body['keys'] ?? null);

            case 'table':
                return (new TableOperations($this->client($grant), $catalog))->run(
                    $database(),
                    $body['tables'] ?? null,
                    (string) ($body['operation'] ?? ''),
                    $body['name'] ?? null,
                );

            case 'design':
                $db = $database();
                $name = $table($db);
                $client = $this->client($grant);

                return [
                    'table' => $catalog->table($db, $name),
                    'columns' => array_map(static fn (array $column): array => Schema::describe($column, $client->isMariaDb()), $catalog->columns($db, $name)),
                    'collations' => $this->collations($client),
                ];

            case 'collations':
                return $this->collations($this->client($grant));

            case 'schema':
                $change = $body['change'] ?? null;

                if (! is_array($change)) {
                    throw new UserError('Describe the change.');
                }

                return (new Schema($this->client($grant), $catalog))->apply($database(), $change, ($body['preview'] ?? true) !== false);

            case 'objects':
                return (new Objects($this->client($grant), $catalog))->all($database());

            case 'definition':
                return ['sql' => (new Objects($this->client($grant), $catalog))->definition($database(), $query['type'] ?? null, $query['name'] ?? null)];

            case 'drop-object':
                (new Objects($this->client($grant), $catalog))->drop($database(), $body['type'] ?? null, $body['name'] ?? null);

                return ['ok' => true];

            case 'search':
                $db = $database();
                $tables = $this->jsonParameter($query['tables'] ?? null);

                return (new Search($this->client($grant), $catalog, $this->rows($catalog)))->run(
                    $db,
                    (string) ($query['q'] ?? ''),
                    $this->tableNames($catalog, $db, $tables),
                    (float) max(1, $this->config->int('import.budget')),
                );

            case 'replace-preview':
                $db = $database();

                return (new Replace($this->client($grant), $catalog))->preview(
                    $db,
                    (string) ($query['q'] ?? ''),
                    (string) ($query['r'] ?? ''),
                    $this->tableNames($catalog, $db, $this->jsonParameter($query['tables'] ?? null)),
                    (float) max(1, $this->config->int('import.budget')),
                );

            case 'replace':
                $db = $database();

                return (new Replace($this->client($grant), $catalog))->run(
                    $db,
                    (string) ($body['search'] ?? ''),
                    (string) ($body['replace'] ?? ''),
                    $this->tableNames($catalog, $db, is_array($body['tables'] ?? null) ? $body['tables'] : []),
                    $body['cursor'] ?? null,
                    (float) max(1, $this->config->int('import.budget')),
                );

            case 'processes':
                return (new Processes($this->client($grant), $grant['user']))->all();

            case 'kill':
                (new Processes($this->client($grant), $grant['user']))->kill($body['id'] ?? null, ($body['connection'] ?? false) === true);

                return ['ok' => true];

            case 'query':
                $sql = $body['sql'] ?? null;

                if (! is_string($sql)) {
                    throw new UserError('Expected SQL to run.');
                }

                $db = isset($query['db']) && $query['db'] !== '' ? $database() : null;

                return (new Console(
                    $this->client($grant),
                    $this->config->int('limits.query_rows'),
                    $this->config->int('limits.cell_preview'),
                ))->run($sql, $db, $grant['readonly'], ($body['confirmed'] ?? false) === true);

            default:
                throw new UserError('Unknown action.', 404);
        }
    }

    /**
     * @param  array{database: ?string, label: string, readonly: bool, user: string}  $grant
     * @return array<string, mixed>
     */
    private function sessionPayload(array $grant, Catalog $catalog): array
    {
        $client = $this->client($grant);
        $initial = $grant['database'] !== null && in_array($grant['database'], $catalog->databaseNames(), true)
            ? $grant['database']
            : null;

        return [
            'label' => $grant['label'],
            'user' => $grant['user'],
            'database' => $initial,
            'readonly' => $grant['readonly'],
            'server' => [
                'version' => $client->version(),
                'mariadb' => $client->isMariaDb(),
            ],
            'limits' => [
                'pageSize' => $this->config->int('limits.page_size'),
                'queryRows' => $this->config->int('limits.query_rows'),
                'cellPreview' => $this->config->int('limits.cell_preview'),
            ],
            'operators' => Rows::OPERATORS,
            'import' => [
                'maxBytes' => $this->config->int('import.max_bytes'),
            ],
        ];
    }

    /**
     * @param  array{user: string, password: ?string}  $grant
     */
    private function client(array $grant): Client
    {
        return $this->client ??= $this->connection->open($grant);
    }

    /**
     * @param  array{user: string, password: ?string}  $grant
     */
    private function catalog(array $grant): Catalog
    {
        $hidden = $this->config->get('hidden_databases');

        return new Catalog($this->client($grant), is_array($hidden) ? array_values(array_filter($hidden, is_string(...))) : []);
    }

    /**
     * Collation names to offer in the structure forms: the utf8mb4 ones,
     * which are what a new column should almost always use, then the rest
     * of the common character sets.
     *
     * @return list<string>
     */
    private function collations(Client $client): array
    {
        $names = array_column($client->select(
            "SHOW COLLATION WHERE Charset IN ('utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'ascii', 'binary')"
        ), 'Collation');

        usort($names, static fn (string $a, string $b): int => [! str_starts_with($a, 'utf8mb4'), $a] <=> [! str_starts_with($b, 'utf8mb4'), $b]);

        return array_values(array_map(strval(...), $names));
    }

    /**
     * Tables named by the browser, each resolved through the catalog.
     *
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private function tableNames(Catalog $catalog, string $database, array $names): array
    {
        return array_map(static fn (mixed $name): string => $catalog->table($database, $name)['name'], array_values($names));
    }

    private function editor(Catalog $catalog): Editor
    {
        return new Editor(
            $this->client ?? throw new UserError('Not connected.', 500),
            $catalog,
            $this->config->int('limits.value_preview'),
        );
    }

    private function rows(Catalog $catalog): Rows
    {
        return new Rows(
            $this->client ?? throw new UserError('Not connected.', 500),
            $catalog,
            $this->config->int('limits.cell_preview'),
            $this->config->int('limits.value_preview'),
            $this->config->int('limits.exact_count'),
            (bool) $this->config->get('decode_serialized'),
        );
    }

    /**
     * A JSON-encoded query parameter, as an array.
     *
     * @return array<mixed>
     */
    private function jsonParameter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            throw new UserError('Invalid request.');
        }

        return $decoded;
    }
}
