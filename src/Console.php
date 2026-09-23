<?php

declare(strict_types=1);

namespace DbAdmin;

use DbAdmin\Sql\Analyzer;
use DbAdmin\Sql\Splitter;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * Runs the SQL a user types, one statement at a time.
 *
 * Statements run in order and stop at the first error. Those that ran before
 * it have taken effect, as they would in any client. A statement that throws
 * data away (DROP, TRUNCATE, a DELETE or UPDATE with no WHERE) is not run
 * until the user has confirmed it.
 *
 * Result sets are cut off at the configured row count and at a byte budget,
 * so one careless SELECT cannot build a response the browser chokes on. The
 * server does the cutting where it can: sql_select_limit stops a plain SELECT
 * early without touching the text of the query.
 */
final class Console
{
    private const int MAX_STATEMENTS = 500;

    private const int RESPONSE_BUDGET = 8 * 1024 * 1024;

    private const int SQL_EXCERPT = 2000;

    public function __construct(
        private readonly Client $client,
        private readonly int $rowLimit,
        private readonly int $cellPreview,
    ) {}

    /**
     * @return array{results?: list<array<string, mixed>>, confirm?: list<array{index: int, line: int, reason: string, sql: string}>}
     */
    public function run(string $sql, ?string $database, bool $readOnly, bool $confirmed): array
    {
        $statements = Splitter::split($sql);

        if ($statements === []) {
            throw new UserError('There is nothing to run.');
        }

        if (count($statements) > self::MAX_STATEMENTS) {
            throw new UserError(sprintf('Run at most %d statements at once. Use Import for larger scripts.', self::MAX_STATEMENTS));
        }

        $destructive = [];

        foreach ($statements as $index => $statement) {
            $analysis = Analyzer::analyze($statement->sql);

            if ($readOnly && ! $analysis['readOnly']) {
                throw new UserError(sprintf('This session is read-only, and statement %d (line %d) would change something.', $index + 1, $statement->line), 403);
            }

            if ($analysis['destructive'] !== null) {
                $destructive[] = [
                    'index' => $index,
                    'line' => $statement->line,
                    'reason' => $analysis['destructive'],
                    'sql' => self::excerpt($statement->sql, 300),
                ];
            }
        }

        if ($destructive !== [] && ! $confirmed) {
            return ['confirm' => $destructive];
        }

        $mysqli = $this->client->mysqli();

        if ($database !== null) {
            $this->client->useDatabase($database);
        }

        $this->client->query(sprintf('SET SESSION sql_select_limit = %d', $this->rowLimit + 1));

        if ($readOnly) {
            $this->client->query('SET SESSION TRANSACTION READ ONLY');
        }

        $results = [];
        $budget = self::RESPONSE_BUDGET;

        foreach ($statements as $index => $statement) {
            $started = hrtime(true);

            try {
                $sets = $this->execute($mysqli, $statement->sql, $budget);
            } catch (mysqli_sql_exception $e) {
                $results[] = [
                    'index' => $index,
                    'line' => $statement->line,
                    'sql' => self::excerpt($statement->sql),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];

                break;
            }

            $results[] = [
                'index' => $index,
                'line' => $statement->line,
                'sql' => self::excerpt($statement->sql),
                'ms' => round((hrtime(true) - $started) / 1e6, 1),
                'sets' => $sets,
                'warnings' => $this->warnings($mysqli),
            ];
        }

        return [
            'results' => $results,
            'total' => count($statements),
            'database' => $this->currentDatabase(),
        ];
    }

    /**
     * Run one statement and collect every result set it returns (a CALL can
     * return several), or what it changed.
     *
     * @return list<array<string, mixed>>
     */
    private function execute(mysqli $mysqli, string $sql, int &$budget): array
    {
        $mysqli->real_query($sql);
        $sets = [];

        do {
            if ($mysqli->field_count === 0) {
                $sets[] = [
                    'affected' => max(0, (int) $mysqli->affected_rows),
                    'insertId' => $mysqli->insert_id ? (string) $mysqli->insert_id : null,
                    'info' => $mysqli->info ?: null,
                ];

                continue;
            }

            $result = $mysqli->use_result();

            if (! $result instanceof mysqli_result) {
                continue;
            }

            $sets[] = $this->collect($result, $budget);
        } while ($mysqli->more_results() && $mysqli->next_result());

        return $sets;
    }

    /**
     * @return array{columns: list<array{name: string, table: string, type: string, numeric: bool}>, rows: list<list<mixed>>, rowCount: int, truncated: bool}
     */
    private function collect(mysqli_result $result, int &$budget): array
    {
        $columns = array_map(static fn (object $field): array => [
            'name' => (string) $field->name,
            'table' => (string) $field->table,
            'type' => ColumnType::name((int) $field->type, (int) $field->charsetnr),
            'numeric' => ColumnType::isNumeric((int) $field->type),
        ], $result->fetch_fields());

        $rows = [];
        $truncated = false;
        $count = 0;

        while (($values = $result->fetch_row()) !== null && $values !== false) {
            $count++;

            if ($truncated) {
                continue;
            }

            if (count($rows) >= $this->rowLimit || $budget <= 0) {
                $truncated = true;

                continue;
            }

            $row = [];

            foreach ($values as $value) {
                [$preview, $length] = Codec::preview($value === null ? null : (string) $value, $this->cellPreview);
                $row[] = Codec::cell($preview, $length);
                $budget -= $preview === null ? 4 : strlen($preview) + 8;
            }

            $rows[] = $row;
        }

        $result->free();

        return [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => $count,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return list<array{level: string, code: int, message: string}>
     */
    private function warnings(mysqli $mysqli): array
    {
        if ($mysqli->warning_count === 0) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'level' => (string) $row['Level'],
            'code' => (int) $row['Code'],
            'message' => (string) $row['Message'],
        ], $this->client->select('SHOW WARNINGS LIMIT 20'));
    }

    /**
     * The default database after the statements ran: a USE among them moves it.
     */
    private function currentDatabase(): ?string
    {
        try {
            return $this->client->value('SELECT DATABASE()');
        } catch (UserError) {
            return null;
        }
    }

    private static function excerpt(string $sql, int $length = self::SQL_EXCERPT): string
    {
        return strlen($sql) > $length ? mb_strcut($sql, 0, $length, 'UTF-8').'…' : $sql;
    }
}
