<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * The programmable objects in a database: views, stored procedures and
 * functions, triggers and events. Listing them, showing how each is defined,
 * and dropping them.
 *
 * An object named by the browser is only acted on once it is found in the
 * list the server reports for the database, like tables are.
 */
final class Objects
{
    public const array TYPES = ['view', 'procedure', 'function', 'trigger', 'event'];

    public function __construct(private readonly Client $client, private readonly Catalog $catalog) {}

    /**
     * @return array{views: list<array<string, mixed>>, procedures: list<array<string, mixed>>, functions: list<array<string, mixed>>, triggers: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function all(string $database): array
    {
        $routines = $this->client->select(sprintf(
            'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type, DTD_IDENTIFIER AS returns, SECURITY_TYPE AS security,
                    CREATED AS created, LAST_ALTERED AS altered, ROUTINE_COMMENT AS comment
             FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = %s ORDER BY ROUTINE_NAME',
            $this->client->quote($database),
        ));

        $routine = static fn (array $row): array => [
            'name' => (string) $row['name'],
            'returns' => $row['returns'],
            'security' => (string) $row['security'],
            'created' => $row['created'],
            'altered' => $row['altered'],
            'comment' => (string) $row['comment'],
        ];

        return [
            'views' => array_values(array_map(
                static fn (array $table): array => ['name' => $table['name']],
                array_filter($this->catalog->tables($database), static fn (array $table): bool => $table['view']),
            )),
            'procedures' => array_values(array_map($routine, array_filter($routines, static fn (array $row): bool => $row['type'] === 'PROCEDURE'))),
            'functions' => array_values(array_map($routine, array_filter($routines, static fn (array $row): bool => $row['type'] === 'FUNCTION'))),
            'triggers' => array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'table' => (string) $row['table_name'],
                'timing' => (string) $row['timing'],
                'event' => (string) $row['event'],
            ], $this->client->select(sprintf(
                'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS table_name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event
                 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME',
                $this->client->quote($database),
            ))),
            'events' => array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'schedule' => $row['execute_at'] !== null
                    ? 'once at '.$row['execute_at']
                    : 'every '.$row['interval_value'].' '.strtolower((string) $row['interval_field']),
                'lastRun' => $row['last_executed'],
            ], $this->client->select(sprintf(
                'SELECT EVENT_NAME AS name, STATUS AS status, EXECUTE_AT AS execute_at, INTERVAL_VALUE AS interval_value,
                        INTERVAL_FIELD AS interval_field, LAST_EXECUTED AS last_executed
                 FROM information_schema.EVENTS WHERE EVENT_SCHEMA = %s ORDER BY EVENT_NAME',
                $this->client->quote($database),
            ))),
        ];
    }

    /**
     * How an object is defined: its CREATE statement, or null when the
     * server does not show it to this user.
     */
    public function definition(string $database, mixed $type, mixed $name): ?string
    {
        [$type, $name] = $this->resolve($database, $type, $name);

        $rows = $this->client->selectRows(sprintf('SHOW CREATE %s %s', strtoupper($type), Identifier::qualified($database, $name)));
        $row = $rows[0] ?? [];

        // SHOW CREATE puts the statement in a different column per type.
        $statement = match ($type) {
            'view' => $row[1] ?? null,
            'procedure', 'function', 'trigger' => $row[2] ?? null,
            'event' => $row[3] ?? null,
        };

        return is_string($statement) && $statement !== '' ? $statement : null;
    }

    public function drop(string $database, mixed $type, mixed $name): void
    {
        [$type, $name] = $this->resolve($database, $type, $name);

        $this->client->query(sprintf('DROP %s %s', strtoupper($type), Identifier::qualified($database, $name)));
    }

    /**
     * Statements that recreate every procedure, function and event, for a
     * dump: definers left out, and wrapped in DELIMITER so their bodies can
     * hold semicolons. An object the server will not show is noted instead.
     */
    public function dump(string $database): string
    {
        $all = $this->all($database);
        $out = '';

        foreach ([['function', $all['functions']], ['procedure', $all['procedures']], ['event', $all['events']]] as [$type, $objects]) {
            foreach ($objects as $object) {
                $definition = $this->definition($database, $type, $object['name']);

                if ($definition === null) {
                    $out .= sprintf("-- The %s %s could not be read, and is not in this dump.\n\n", $type, $object['name']);

                    continue;
                }

                $out .= sprintf(
                    "--\n-- %s %s\n--\n\nDROP %s IF EXISTS %s;\nDELIMITER ;;\n%s;;\nDELIMITER ;\n\n",
                    ucfirst($type),
                    $object['name'],
                    strtoupper($type),
                    Identifier::quote($object['name']),
                    Export::withoutDefiner($definition),
                );
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(string $database, mixed $type, mixed $name): array
    {
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            throw new UserError('Unknown kind of object.');
        }

        $all = $this->all($database);

        foreach ($all[$type.'s'] as $object) {
            if ($object['name'] === $name) {
                return [$type, $object['name']];
            }
        }

        throw new UserError(sprintf('That %s does not exist.', $type), 404);
    }
}
