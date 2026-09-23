<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * Reads a table's rows a page at a time, and single values in full.
 *
 * Every name in the SQL built here is one the {@see Catalog} reported for the
 * table, and every value is quoted by the connection, so nothing the browser
 * sends is ever pasted into a statement.
 */
final class Rows
{
    /**
     * Filter operators the browser may ask for.
     */
    public const array OPERATORS = [
        '=', '!=', '<', '>', '<=', '>=',
        'contains', 'not contains', 'starts with', 'ends with',
        'like', 'not like', 'in', 'not in',
        'is null', 'is not null', 'is empty', 'is not empty',
    ];

    /**
     * Types whose values can be long enough that only their start is read
     * for a page of rows.
     */
    private const array LONG_TYPES = [
        'tinytext', 'text', 'mediumtext', 'longtext', 'json',
        'tinyblob', 'blob', 'mediumblob', 'longblob',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    private const array TEXT_TYPES = [
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'enum', 'set',
    ];

    private const array NUMERIC_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year', 'bit',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly Catalog $catalog,
        private readonly int $cellPreview,
        private readonly int $valuePreview,
        private readonly int $exactCountLimit,
        private readonly bool $decodeSerialized = true,
    ) {}

    /**
     * One page of a table's rows.
     *
     * @param  array{offset?: int, limit?: int, sort?: ?string, direction?: string, filters?: list<array{column: string, operator: string, value?: mixed}>, search?: string}  $options
     * @return array<string, mixed>
     */
    public function page(string $database, string $table, array $options): array
    {
        $summary = $this->catalog->table($database, $table);
        $columns = $this->catalog->columns($database, $table);
        $key = $summary['view'] ? null : Catalog::rowKey($columns, $this->catalog->indexes($database, $table));
        $byName = array_column($columns, null, 'name');

        $offset = max(0, (int) ($options['offset'] ?? 0));
        $limit = max(1, min(1000, (int) ($options['limit'] ?? 50)));
        $sort = $options['sort'] ?? null;
        $direction = strtolower((string) ($options['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        if ($sort !== null && ! isset($byName[$sort])) {
            throw new UserError('Unknown column to sort by.');
        }

        $where = $this->where($byName, $options['filters'] ?? [], (string) ($options['search'] ?? ''));

        $order = [];

        if ($sort !== null) {
            $order[] = Identifier::quote($sort).' '.$direction;
        }

        // The row key breaks ties, so paging is stable.
        foreach ($key ?? [] as $name) {
            if ($name !== $sort) {
                $order[] = Identifier::quote($name).($sort === null ? '' : ' '.$direction);
            }
        }

        $select = [];
        $long = [];

        foreach ($columns as $index => $column) {
            $quoted = Identifier::quote($column['name']);

            if ($this->isLong($column)) {
                $long[$index] = count($columns) + count($long);
                $select[] = sprintf('LEFT(%s, %d)', $quoted, $this->cellPreview);
            } elseif ($column['dataType'] === 'bit') {
                // BIT values are bytes on the wire; as numbers they read and edit.
                $select[] = $quoted.' + 0';
            } else {
                $select[] = $quoted;
            }
        }

        foreach (array_keys($long) as $index) {
            $select[] = sprintf('OCTET_LENGTH(%s)', Identifier::quote($columns[$index]['name']));
        }

        $from = Identifier::qualified($database, $table);

        // One row more than the page shows whether another page follows.
        $raw = $this->client->selectRows(sprintf(
            'SELECT %s FROM %s%s%s LIMIT %d OFFSET %d',
            implode(', ', $select),
            $from,
            $where === '' ? '' : ' WHERE '.$where,
            $order === [] ? '' : ' ORDER BY '.implode(', ', $order),
            $limit + 1,
            $offset,
        ));

        $hasMore = count($raw) > $limit;
        $rows = [];

        foreach (array_slice($raw, 0, $limit) as $values) {
            $row = [];

            foreach ($columns as $index => $column) {
                $value = $values[$index];
                $fullLength = isset($long[$index]) && $values[$long[$index]] !== null ? (int) $values[$long[$index]] : null;
                [$preview, $cutLength] = Codec::preview($value, $this->cellPreview);
                $row[] = Codec::cell($preview, $fullLength ?? $cutLength);
            }

            $rows[] = $row;
        }

        [$total, $exact] = $this->count($from, $where, $summary);

        return [
            'columns' => array_map(fn (array $column): array => [
                'name' => $column['name'],
                'type' => $column['type'],
                'numeric' => in_array($column['dataType'], self::NUMERIC_TYPES, true),
                'nullable' => $column['nullable'],
                'key' => in_array($column['name'], $key ?? [], true),
            ], $columns),
            'rows' => $rows,
            'key' => $key,
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => $hasMore,
            'total' => $total,
            'exact' => $exact,
        ];
    }

    /**
     * One value in full (up to the value preview limit), with a readable
     * rendering when it holds JSON or serialized PHP.
     *
     * @param  array<string, mixed>  $key  row key column => value
     * @return array{value: string|array<string, string|int>|null, format: ?string, pretty: ?string}
     */
    public function value(string $database, string $table, mixed $column, array $key): array
    {
        $columns = array_column($this->catalog->columns($database, $table), null, 'name');

        if (! is_string($column) || ! isset($columns[$column])) {
            throw new UserError('Unknown column.');
        }

        $where = RowKey::of($this->catalog, $database, $table)->where($this->client, $key);

        $quoted = Identifier::quote($column);
        $rows = $this->client->selectRows(sprintf(
            'SELECT LEFT(%1$s, %2$d), OCTET_LENGTH(%1$s) FROM %3$s WHERE %4$s LIMIT 2',
            $quoted,
            $this->valuePreview,
            Identifier::qualified($database, $table),
            $where,
        ));

        if (count($rows) !== 1) {
            throw new UserError($rows === [] ? 'That row no longer exists.' : 'More than one row matches.', 404);
        }

        [$bytes, $length] = [$rows[0][0], $rows[0][1] === null ? null : (int) $rows[0][1]];
        [$preview, $cut] = Codec::preview($bytes, $this->valuePreview);
        $fullLength = $length ?? $cut;
        $complete = $bytes === null || $fullLength === null || $fullLength <= strlen((string) $preview);

        $described = $bytes === null ? ['format' => null, 'pretty' => null] : (new Formatter($this->decodeSerialized))->describe((string) $preview, $complete);

        return [
            'value' => Codec::cell($preview, $complete ? null : $fullLength),
            'format' => $described['format'],
            'pretty' => $described['pretty'],
        ];
    }

    /**
     * The WHERE clause the browser's filters and search stand for, or "",
     * for exporting exactly the rows a page is showing.
     */
    public function filterCondition(string $database, string $table, mixed $filters, string $search): string
    {
        return $this->where(array_column($this->catalog->columns($database, $table), null, 'name'), $filters, $search);
    }

    /**
     * The WHERE clause for a set of filters and a search, or "".
     *
     * @param  array<string, array{name: string, dataType: string}>  $columns
     * @param  mixed  $filters
     */
    private function where(array $columns, mixed $filters, string $search): string
    {
        $conditions = [];

        foreach (is_array($filters) ? $filters : [] as $filter) {
            if (! is_array($filter)) {
                throw new UserError('Invalid filter.');
            }

            $conditions[] = $this->condition($columns, $filter);
        }

        $search = trim($search);

        if ($search !== '') {
            $matches = [];

            foreach ($columns as $column) {
                if (in_array($column['dataType'], self::TEXT_TYPES, true)) {
                    $matches[] = Identifier::quote($column['name']).' LIKE '.$this->client->quoteLike($search, '%', '%');
                } elseif (is_numeric($search) && in_array($column['dataType'], self::NUMERIC_TYPES, true)) {
                    $matches[] = Identifier::quote($column['name']).' = '.$this->client->quote($search);
                }
            }

            $conditions[] = $matches === [] ? '0' : '('.implode(' OR ', $matches).')';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @param  array<string, array{name: string}>  $columns
     * @param  array<mixed>  $filter
     */
    private function condition(array $columns, array $filter): string
    {
        $name = $filter['column'] ?? null;
        $operator = strtolower((string) ($filter['operator'] ?? ''));

        if (! is_string($name) || ! isset($columns[$name])) {
            throw new UserError('Unknown column to filter on.');
        }

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new UserError('Unknown filter operator.');
        }

        $column = Identifier::quote($name);
        $raw = $filter['value'] ?? '';
        $value = is_scalar($raw) ? (string) $raw : '';

        return match ($operator) {
            '=', '!=', '<', '>', '<=', '>=' => sprintf('%s %s %s', $column, $operator, $this->client->quote($value)),
            'contains' => $column.' LIKE '.$this->client->quoteLike($value, '%', '%'),
            'not contains' => $column.' NOT LIKE '.$this->client->quoteLike($value, '%', '%'),
            'starts with' => $column.' LIKE '.$this->client->quoteLike($value, '', '%'),
            'ends with' => $column.' LIKE '.$this->client->quoteLike($value, '%'),
            'like' => $column.' LIKE '.$this->client->quote($value),
            'not like' => $column.' NOT LIKE '.$this->client->quote($value),
            'in', 'not in' => sprintf(
                '%s %s (%s)',
                $column,
                strtoupper($operator),
                implode(', ', array_map(fn (string $item): string => $this->client->quote(trim($item)), explode(',', $value))),
            ),
            'is null' => $column.' IS NULL',
            'is not null' => $column.' IS NOT NULL',
            'is empty' => sprintf("(%1\$s IS NULL OR %1\$s = '')", $column),
            'is not empty' => sprintf("(%1\$s IS NOT NULL AND %1\$s <> '')", $column),
        };
    }

    /**
     * The number of rows, and whether it is exact.
     *
     * Counting a large InnoDB table means reading all of it, so without a
     * filter a large table shows the server's estimate instead.
     *
     * @param  array<string, mixed>  $summary
     * @return array{0: ?int, 1: bool}
     */
    private function count(string $from, string $where, array $summary): array
    {
        if ($where === '' && ($summary['rowsExact'] || ($summary['rows'] !== null && $summary['rows'] > $this->exactCountLimit))) {
            return [$summary['rows'], $summary['rowsExact']];
        }

        return [(int) $this->client->value(sprintf('SELECT COUNT(*) FROM %s%s', $from, $where === '' ? '' : ' WHERE '.$where)), true];
    }

    /**
     * @param  array{dataType: string, octetLength: ?int}  $column
     */
    private function isLong(array $column): bool
    {
        return in_array($column['dataType'], self::LONG_TYPES, true)
            || ($column['octetLength'] !== null && $column['octetLength'] > $this->cellPreview);
    }
}
