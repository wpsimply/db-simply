<?php

declare(strict_types=1);

namespace DbSimply;

use mysqli_sql_exception;

/**
 * Search and replace across a database, safe for serialized PHP.
 *
 * The text is looked for in every text column of every table, byte for byte
 * (so case matters), and replaced with {@see Serialized::replace()}: inside
 * serialized values the strings are rewritten with their lengths corrected,
 * everywhere else the text is simply replaced. This is what moving a
 * WordPress site to a new domain needs.
 *
 * A preview counts the matching rows and shows a few before-and-after
 * examples, and changes nothing. The replacement itself runs in slices of a
 * few seconds, like an import: each call works through rows in the order of
 * their key and hands back where it stopped, so any size of database can be
 * processed without a request running into a timeout. Rows are only ever
 * changed through their row key, so a table without one is reported and left
 * alone.
 */
final class Replace
{
    private const array TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    private const int BATCH = 100;

    private const int SAMPLES = 3;

    private const int SAMPLE_CONTEXT = 60;

    public function __construct(private readonly Client $client, private readonly Catalog $catalog) {}

    /**
     * Count what would change, table by table, with a few examples.
     *
     * @param  list<string>  $tables  resolved table names, or [] for all
     * @return array{tables: list<array<string, mixed>>, skipped: list<string>}
     */
    public function preview(string $database, string $search, string $replace, array $tables, float $budget): array
    {
        self::validate($search, $replace);

        $deadline = microtime(true) + $budget;
        $results = [];
        $skipped = [];

        foreach ($this->targets($database, $tables) as $target) {
            if (microtime(true) >= $deadline) {
                $skipped[] = $target['table'];

                continue;
            }

            $matches = (int) $this->client->value(sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s',
                Identifier::qualified($database, $target['table']),
                $this->matching($target['columns'], $search),
            ));

            if ($matches === 0) {
                continue;
            }

            $results[] = [
                'table' => $target['table'],
                'rows' => $matches,
                'editable' => $target['key'] !== null,
                'samples' => $this->samples($database, $target, $search, $replace),
            ];
        }

        return ['tables' => $results, 'skipped' => $skipped];
    }

    /**
     * Replace for a while, from where the last call stopped.
     *
     * @param  list<string>  $tables  resolved table names, or [] for all
     * @param  mixed  $cursor  null to start, or what the last call returned
     * @return array{cursor: ?array{table: string, after: ?list<?string>}, changed: array<string, int>, done: bool}
     */
    public function run(string $database, string $search, string $replace, array $tables, mixed $cursor, float $budget): array
    {
        self::validate($search, $replace);

        $deadline = microtime(true) + $budget;
        $targets = array_values(array_filter($this->targets($database, $tables), static fn (array $target): bool => $target['key'] !== null));
        $names = array_column($targets, 'table');
        $index = 0;
        $after = null;

        if (is_array($cursor)) {
            $index = array_search($cursor['table'] ?? null, $names, true);

            if ($index === false) {
                throw new UserError('The replacement lost its place. Start it again; what has been replaced stays replaced.');
            }

            $after = is_array($cursor['after'] ?? null) ? array_values($cursor['after']) : null;
        }

        $changed = [];

        for (; $index < count($targets); $index++) {
            $target = $targets[$index];

            while (true) {
                [$rows, $last] = $this->batch($database, $target, $search, $replace, $after);

                if ($rows > 0) {
                    $changed[$target['table']] = ($changed[$target['table']] ?? 0) + $rows;
                }

                // A short batch was the last of this table.
                if ($last === null) {
                    $after = null;

                    break;
                }

                $after = $last;

                if (microtime(true) >= $deadline) {
                    return ['cursor' => ['table' => $target['table'], 'after' => $after], 'changed' => $changed, 'done' => false];
                }
            }

            if (microtime(true) >= $deadline && $index + 1 < count($targets)) {
                return ['cursor' => ['table' => $targets[$index + 1]['table'], 'after' => null], 'changed' => $changed, 'done' => false];
            }
        }

        return ['cursor' => null, 'changed' => $changed, 'done' => true];
    }

    /**
     * Replace in one batch of matching rows after the given key.
     *
     * @param  array{table: string, key: ?list<string>, columns: list<string>}  $target
     * @param  ?list<?string>  $after
     * @return array{0: int, 1: ?list<?string>}  rows changed, and the last key read when the batch was full
     */
    private function batch(string $database, array $target, string $search, string $replace, ?array $after): array
    {
        $key = $target['key'];
        $quotedKey = array_map(Identifier::quote(...), $key);
        $where = $this->matching($target['columns'], $search);

        if ($after !== null) {
            if (count($after) !== count($key)) {
                throw new UserError('The replacement lost its place. Start it again; what has been replaced stays replaced.');
            }

            // Row comparison walks a composite key in index order.
            $where .= sprintf(
                ' AND (%s) > (%s)',
                implode(', ', $quotedKey),
                implode(', ', array_map($this->client->quote(...), array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $after))),
            );
        }

        $from = Identifier::qualified($database, $target['table']);
        $rows = $this->client->select(sprintf(
            'SELECT %s, %s FROM %s WHERE %s ORDER BY %s LIMIT %d',
            implode(', ', array_map(static fn (string $name): string => Identifier::quote($name).' AS '.Identifier::quote('k:'.$name), $key)),
            implode(', ', array_map(Identifier::quote(...), $target['columns'])),
            $from,
            $where,
            implode(', ', $quotedKey),
            self::BATCH,
        ));

        $changed = 0;

        if ($rows !== []) {
            $this->client->query('START TRANSACTION');

            try {
                foreach ($rows as $row) {
                    $assignments = [];

                    foreach ($target['columns'] as $column) {
                        $value = $row[$column];

                        if ($value === null || ! str_contains($value, $search)) {
                            continue;
                        }

                        $new = Serialized::replace($value, $search, $replace);

                        if ($new !== $value) {
                            $assignments[] = Identifier::quote($column).' = '.$this->client->quote($new);
                        }
                    }

                    if ($assignments === []) {
                        continue;
                    }

                    $this->client->mysqli()->query(sprintf(
                        'UPDATE %s SET %s WHERE %s LIMIT 1',
                        $from,
                        implode(', ', $assignments),
                        implode(' AND ', array_map(fn (string $name): string => Identifier::quote($name).' = '.$this->client->quote($row['k:'.$name]), $key)),
                    ));

                    $changed++;
                }

                $this->client->query('COMMIT');
            } catch (mysqli_sql_exception $e) {
                $this->client->query('ROLLBACK');

                throw new UserError(sprintf('Replacing in %s failed: %s', $target['table'], $e->getMessage()));
            }
        }

        $last = count($rows) === self::BATCH
            ? array_map(static fn (string $name): ?string => $rows[count($rows) - 1]['k:'.$name], $key)
            : null;

        return [$changed, $last];
    }

    /**
     * A few matching values, cut down to the text around the first match,
     * before and after the replacement.
     *
     * @param  array{table: string, key: ?list<string>, columns: list<string>}  $target
     * @return list<array{column: string, before: string, after: string, serialized: bool}>
     */
    private function samples(string $database, array $target, string $search, string $replace): array
    {
        $samples = [];

        foreach ($target['columns'] as $column) {
            if (count($samples) >= self::SAMPLES) {
                break;
            }

            $values = $this->client->selectRows(sprintf(
                'SELECT %1$s FROM %2$s WHERE %3$s LIMIT %4$d',
                Identifier::quote($column),
                Identifier::qualified($database, $target['table']),
                $this->matching([$column], $search),
                self::SAMPLES - count($samples),
            ));

            foreach ($values as [$value]) {
                $value = (string) $value;
                $at = (int) strpos($value, $search);
                $after = Serialized::replace($value, $search, $replace);
                $start = max(0, $at - self::SAMPLE_CONTEXT);

                $samples[] = [
                    'column' => $column,
                    'before' => self::excerpt($value, $start, $at + strlen($search) + self::SAMPLE_CONTEXT - $start),
                    'after' => self::excerpt($after, $start, $at + strlen($replace) + self::SAMPLE_CONTEXT - $start),
                    'serialized' => Serialized::isSerialized($value),
                ];
            }
        }

        return $samples;
    }

    /**
     * Every table to search, with its text columns and row key.
     *
     * @param  list<string>  $only
     * @return list<array{table: string, key: ?list<string>, columns: list<string>}>
     */
    private function targets(string $database, array $only): array
    {
        $targets = [];

        foreach ($this->catalog->tables($database) as $table) {
            if ($table['view'] || ($only !== [] && ! in_array($table['name'], $only, true))) {
                continue;
            }

            $columns = $this->catalog->columns($database, $table['name']);
            $key = Catalog::rowKey($columns, $this->catalog->indexes($database, $table['name']));

            // The row key is what finds a row again, so it is never rewritten.
            $text = array_values(array_map(
                static fn (array $column): string => $column['name'],
                array_filter($columns, static fn (array $column): bool => in_array($column['dataType'], self::TEXT_TYPES, true)
                    && ! in_array($column['name'], $key ?? [], true)
                    && (stripos($column['extra'], 'generated') === false || stripos($column['extra'], 'default_generated') !== false)),
            ));

            if ($text === []) {
                continue;
            }

            $targets[] = ['table' => $table['name'], 'key' => $key, 'columns' => $text];
        }

        return $targets;
    }

    /**
     * Rows where any of the columns contains the text, byte for byte.
     *
     * @param  list<string>  $columns
     */
    private function matching(array $columns, string $search): string
    {
        $pattern = $this->client->quoteLike($search, '%', '%');

        return '('.implode(' OR ', array_map(
            static fn (string $column): string => sprintf('CAST(%s AS BINARY) LIKE CAST(%s AS BINARY)', Identifier::quote($column), $pattern),
            $columns,
        )).')';
    }

    private static function validate(string $search, string $replace): void
    {
        if ($search === '') {
            throw new UserError('Enter the text to replace.');
        }

        if ($search === $replace) {
            throw new UserError('The replacement is the same as the text it replaces.');
        }
    }

    private static function excerpt(string $value, int $start, int $length): string
    {
        return ($start > 0 ? '…' : '').Codec::display(mb_strcut($value, $start, $length, 'UTF-8')).($start + $length < strlen($value) ? '…' : '');
    }
}
