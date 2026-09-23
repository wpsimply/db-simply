<?php

declare(strict_types=1);

namespace DbSimply;

use mysqli_sql_exception;

/**
 * Reads one row in full for editing, and inserts, updates and deletes rows.
 *
 * An existing row is only ever reached through its {@see RowKey}, and every
 * change touches at most one row per key: an UPDATE or DELETE carries
 * LIMIT 1 on top of a condition that can match one row only. Values are
 * written exactly as given -- null is NULL, and a column left out of an
 * insert gets its default -- and generated columns are never written.
 */
final class Editor
{
    private const int MAX_DELETE = 1000;

    private const array BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    private const array LONG_TYPES = ['text', 'mediumtext', 'longtext', 'json', 'blob', 'mediumblob', 'longblob'];

    private const array NUMERIC_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year', 'bit',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly Catalog $catalog,
        private readonly int $valuePreview,
    ) {}

    /**
     * The columns of a table as an edit form needs them.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(string $database, string $table): array
    {
        return array_map(static fn (array $column): array => [
            'name' => $column['name'],
            'type' => $column['type'],
            'dataType' => $column['dataType'],
            'nullable' => $column['nullable'],
            'default' => $column['default'],
            'extra' => $column['extra'],
            'generated' => self::isGenerated($column),
            'autoIncrement' => stripos($column['extra'], 'auto_increment') !== false,
            'numeric' => in_array($column['dataType'], self::NUMERIC_TYPES, true),
            'binary' => in_array($column['dataType'], self::BINARY_TYPES, true),
            'long' => in_array($column['dataType'], self::LONG_TYPES, true),
            'options' => in_array($column['dataType'], ['enum', 'set'], true) ? self::options($column['type']) : null,
            'comment' => $column['comment'],
        ], $this->catalog->columns($database, $table));
    }

    /**
     * One row's values, each in full up to the value preview limit. A value
     * longer than that comes back truncated and cannot be edited here.
     *
     * @return array{fields: list<array<string, mixed>>, values: array<string, mixed>, key: list<string>}
     */
    public function row(string $database, string $table, mixed $key): array
    {
        $rowKey = RowKey::of($this->catalog, $database, $table);
        $fields = $this->fields($database, $table);

        $select = [];

        foreach ($fields as $field) {
            $quoted = Identifier::quote($field['name']);
            $select[] = $field['dataType'] === 'bit' ? $quoted.' + 0' : sprintf('LEFT(%s, %d)', $quoted, $this->valuePreview + 1);
            $select[] = sprintf('OCTET_LENGTH(%s)', $quoted);
        }

        $rows = $this->client->selectRows(sprintf(
            'SELECT %s FROM %s WHERE %s LIMIT 2',
            implode(', ', $select),
            Identifier::qualified($database, $table),
            $rowKey->where($this->client, $key),
        ));

        if (count($rows) !== 1) {
            throw new UserError($rows === [] ? 'That row no longer exists.' : 'More than one row matches.', 404);
        }

        $values = [];

        foreach ($fields as $index => $field) {
            $value = $rows[0][$index * 2];
            $length = $rows[0][$index * 2 + 1];
            [$preview, $cut] = Codec::preview($value, $this->valuePreview);
            $values[$field['name']] = Codec::cell($preview, $field['dataType'] === 'bit' ? null : ($length === null ? $cut : (int) $length));
        }

        return ['fields' => $fields, 'values' => $values, 'key' => $rowKey->columns];
    }

    /**
     * Insert a row. Columns left out get their default.
     *
     * @param  mixed  $values  column => value
     * @return array{insertId: ?string}
     */
    public function insert(string $database, string $table, mixed $values): array
    {
        $assignments = $this->assignments($database, $table, $values);

        $sql = $assignments === []
            ? sprintf('INSERT INTO %s () VALUES ()', Identifier::qualified($database, $table))
            : sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                Identifier::qualified($database, $table),
                implode(', ', array_keys($assignments)),
                implode(', ', $assignments),
            );

        $this->client->query($sql);
        $insertId = $this->client->mysqli()->insert_id;

        return ['insertId' => $insertId ? (string) $insertId : null];
    }

    /**
     * Change the given columns of one row.
     *
     * @param  mixed  $key  row key column => value
     * @param  mixed  $values  column => value, only the columns that change
     * @return array{changed: int}
     */
    public function update(string $database, string $table, mixed $key, mixed $values): array
    {
        $where = RowKey::of($this->catalog, $database, $table)->where($this->client, $key);
        $assignments = $this->assignments($database, $table, $values);

        if ($assignments === []) {
            return ['changed' => 0];
        }

        $from = Identifier::qualified($database, $table);

        // The row may have gone since the form was opened: say so, rather
        // than reporting a change to nothing as a success.
        if ((int) $this->client->value(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $from, $where)) !== 1) {
            throw new UserError('That row no longer exists.', 404);
        }

        $this->client->query(sprintf(
            'UPDATE %s SET %s WHERE %s LIMIT 1',
            $from,
            implode(', ', array_map(static fn (string $column, string $value): string => $column.' = '.$value, array_keys($assignments), $assignments)),
            $where,
        ));

        return ['changed' => max(0, (int) $this->client->mysqli()->affected_rows)];
    }

    /**
     * Delete rows by their keys, all or none.
     *
     * @param  mixed  $keys  list of row key column => value
     * @return array{deleted: int}
     */
    public function delete(string $database, string $table, mixed $keys): array
    {
        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > self::MAX_DELETE) {
            throw new UserError(sprintf('Select between 1 and %d rows.', self::MAX_DELETE));
        }

        $rowKey = RowKey::of($this->catalog, $database, $table);
        $from = Identifier::qualified($database, $table);
        $conditions = array_map(fn (mixed $key): string => $rowKey->where($this->client, $key), $keys);

        $mysqli = $this->client->mysqli();
        $deleted = 0;

        $this->client->query('START TRANSACTION');

        try {
            foreach ($conditions as $where) {
                $mysqli->query(sprintf('DELETE FROM %s WHERE %s LIMIT 1', $from, $where));
                $deleted += max(0, (int) $mysqli->affected_rows);
            }

            $this->client->query('COMMIT');
        } catch (mysqli_sql_exception $e) {
            $this->client->query('ROLLBACK');

            throw new UserError($e->getMessage());
        } catch (UserError $e) {
            $this->client->query('ROLLBACK');

            throw $e;
        }

        return ['deleted' => $deleted];
    }

    /**
     * Quoted column => SQL value, for the columns given.
     *
     * @return array<string, string>
     */
    private function assignments(string $database, string $table, mixed $values): array
    {
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new UserError('Expected column values.');
        }

        $columns = array_column($this->catalog->columns($database, $table), null, 'name');
        $assignments = [];

        foreach ($values as $name => $value) {
            $column = $columns[$name] ?? null;

            if ($column === null) {
                throw new UserError(sprintf('There is no column named "%s".', $name));
            }

            if (self::isGenerated($column)) {
                throw new UserError(sprintf('The column "%s" is generated, and cannot be written.', $name));
            }

            $bytes = Codec::decode($value);
            $sql = $this->client->quote($bytes);

            // A string is not a number to a BIT column: '1' would store the
            // byte 0x31. As a number it stores what was meant.
            if ($bytes !== null && $column['dataType'] === 'bit') {
                $sql = sprintf('CAST(%s AS UNSIGNED)', $sql);
            }

            $assignments[Identifier::quote($name)] = $sql;
        }

        return $assignments;
    }

    /**
     * @param  array{extra: string}  $column
     */
    private static function isGenerated(array $column): bool
    {
        return stripos($column['extra'], 'generated') !== false && stripos($column['extra'], 'default_generated') === false;
    }

    /**
     * The allowed values of an ENUM or SET column type.
     *
     * @return list<string>
     */
    private static function options(string $type): array
    {
        preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $type, $matches);

        return array_map(static fn (string $option): string => str_replace("''", "'", stripcslashes($option)), $matches[1]);
    }
}
