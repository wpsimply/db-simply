<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Picks one row out of a table by the values of its row key.
 *
 * The row key is the primary key, or else a unique index whose columns are
 * all NOT NULL ({@see Catalog::rowKey()}), so its values name at most one row.
 * A table without one has rows that cannot be told apart, and nothing here
 * will touch a single row of it.
 */
final class RowKey
{
    /**
     * @param  list<string>  $columns
     */
    private function __construct(public readonly array $columns) {}

    /**
     * The row key of a table, or a failure explaining why there is none.
     */
    public static function of(Catalog $catalog, string $database, string $table): self
    {
        $summary = $catalog->table($database, $table);

        $columns = $summary['view'] ? null : Catalog::rowKey(
            $catalog->columns($database, $table),
            $catalog->indexes($database, $table),
        );

        if ($columns === null) {
            throw new UserError($summary['view']
                ? 'Rows of a view cannot be picked out one at a time.'
                : 'This table has no primary or unique key, so a single row cannot be picked out of it.');
        }

        return new self($columns);
    }

    /**
     * The WHERE condition matching the row whose key values are given.
     *
     * @param  mixed  $values  row key column => value, as the browser sends it
     */
    public function where(Client $client, mixed $values): string
    {
        if (! is_array($values)) {
            throw new UserError('The row reference is incomplete.');
        }

        $conditions = [];

        foreach ($this->columns as $name) {
            if (! array_key_exists($name, $values)) {
                throw new UserError('The row reference is incomplete.');
            }

            $value = Codec::decode($values[$name]);

            if ($value === null) {
                throw new UserError('The row reference is incomplete.');
            }

            $conditions[] = Identifier::quote($name).' = '.$client->quote($value);
        }

        return implode(' AND ', $conditions);
    }
}
