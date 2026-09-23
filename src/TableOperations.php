<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Whole-table operations: emptying, dropping, renaming and maintenance.
 *
 * The table is resolved through the {@see Catalog} first, and a new name for
 * a rename is quoted, so only a table the user can see is ever named here.
 */
final class TableOperations
{
    /**
     * Operations the browser may ask for.
     */
    public const array OPERATIONS = ['truncate', 'empty', 'drop', 'rename', 'optimize', 'analyze', 'check', 'repair'];

    private const array MAINTENANCE = ['optimize', 'analyze', 'check', 'repair'];

    public function __construct(private readonly Client $client, private readonly Catalog $catalog) {}

    /**
     * Run an operation on one or more tables.
     *
     * @param  mixed  $tables  list of table names
     * @return array{messages: list<array{table: string, type: string, text: string}>}
     */
    public function run(string $database, mixed $tables, string $operation, mixed $newName = null): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new UserError('Unknown table operation.');
        }

        if (! is_array($tables) || $tables === [] || count($tables) > 500) {
            throw new UserError('Select between 1 and 500 tables.');
        }

        if ($operation === 'rename' && count($tables) !== 1) {
            throw new UserError('Rename one table at a time.');
        }

        $resolved = array_map(fn (mixed $name): array => $this->catalog->table($database, $name), array_values($tables));
        $messages = [];

        if (in_array($operation, self::MAINTENANCE, true)) {
            $names = array_map(static fn (array $table): string => Identifier::qualified($database, $table['name']), array_filter($resolved, static fn (array $table): bool => ! $table['view']));

            if ($names === []) {
                throw new UserError('Views have nothing to '.$operation.'.');
            }

            foreach ($this->client->select(strtoupper($operation).' TABLE '.implode(', ', $names)) as $row) {
                $messages[] = [
                    'table' => (string) preg_replace('/^[^.]*\./', '', (string) ($row['Table'] ?? '')),
                    'type' => (string) ($row['Msg_type'] ?? ''),
                    'text' => (string) ($row['Msg_text'] ?? ''),
                ];
            }

            return ['messages' => $messages];
        }

        foreach ($resolved as $table) {
            $quoted = Identifier::qualified($database, $table['name']);

            if ($table['view'] && $operation !== 'drop' && $operation !== 'rename') {
                throw new UserError(sprintf('"%s" is a view, and has no rows of its own to remove.', $table['name']));
            }

            match ($operation) {
                'truncate' => $this->client->query('TRUNCATE TABLE '.$quoted),
                'empty' => $this->client->query('DELETE FROM '.$quoted),
                'drop' => $this->client->query(($table['view'] ? 'DROP VIEW ' : 'DROP TABLE ').$quoted),
                'rename' => $this->client->query(sprintf('RENAME TABLE %s TO %s', $quoted, Identifier::qualified($database, $this->newName($newName)))),
            };

            $messages[] = ['table' => $table['name'], 'type' => 'status', 'text' => 'OK'];
        }

        return ['messages' => $messages];
    }

    private function newName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';

        if (! Identifier::isValid($name)) {
            throw new UserError('Enter a new name of 1 to 64 characters.');
        }

        return $name;
    }
}
