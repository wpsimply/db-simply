<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Finds a value in every table of a database at once.
 *
 * Each table is searched the way its own search box does ({@see Rows}): text
 * columns containing the term, numeric columns equal to it. Only matches are
 * counted here; opening a table shows them. Searching reads whole tables, so
 * it stops after a time budget and says which tables it did not get to.
 */
final class Search
{
    public function __construct(
        private readonly Client $client,
        private readonly Catalog $catalog,
        private readonly Rows $rows,
    ) {}

    /**
     * @param  list<string>  $tables  resolved table names, or [] for all
     * @return array{results: list<array{table: string, matches: int}>, skipped: list<string>, searched: int}
     */
    public function run(string $database, string $term, array $tables, float $budget): array
    {
        $term = trim($term);

        if ($term === '') {
            throw new UserError('Enter something to search for.');
        }

        $deadline = microtime(true) + $budget;
        $results = [];
        $skipped = [];
        $searched = 0;

        foreach ($this->catalog->tables($database) as $table) {
            if ($table['view'] || ($tables !== [] && ! in_array($table['name'], $tables, true))) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                $skipped[] = $table['name'];

                continue;
            }

            $where = $this->rows->filterCondition($database, $table['name'], [], $term);
            $searched++;

            // A table with no column the term could be in has nothing to count.
            if ($where === '0') {
                continue;
            }

            $matches = (int) $this->client->value(sprintf('SELECT COUNT(*) FROM %s WHERE %s', Identifier::qualified($database, $table['name']), $where));

            if ($matches > 0) {
                $results[] = ['table' => $table['name'], 'matches' => $matches];
            }
        }

        return ['results' => $results, 'skipped' => $skipped, 'searched' => $searched];
    }
}
