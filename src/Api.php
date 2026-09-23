<?php

declare(strict_types=1);

namespace DbAdmin;

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
    private const array READS = ['session', 'databases', 'tables', 'structure', 'rows', 'value'];

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
            throw new UserError('Your session has ended. Open DB Admin again from your control panel.', 401);
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
