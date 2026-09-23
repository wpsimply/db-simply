<?php

declare(strict_types=1);

namespace DbAdmin;

use Closure;
use DbAdmin\Sql\Analyzer;
use DbAdmin\Sql\Splitter;
use mysqli_result;
use mysqli_sql_exception;

/**
 * Writes a database, some of its tables, a table's rows or a query's result
 * out as an SQL dump or CSV, a piece at a time.
 *
 * Nothing is collected in memory: rows are read unbuffered and written to the
 * sink as they arrive, so the size of an export is limited by the time it
 * may take, not by memory.
 *
 * A dump reads every table inside one consistent snapshot, writes TIMESTAMP
 * values in UTC, and leaves DEFINER clauses out of views and triggers, so it
 * can be imported by a user who is not the one who created them.
 */
final class Export
{
    /**
     * Bytes an extended INSERT grows to before a new one is started; well
     * under the smallest max_allowed_packet a server ships with.
     */
    private const int INSERT_BYTES = 1024 * 1024;

    private const array BINARY_FIELD_TYPES = [
        MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB,
        MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_STRING, MYSQLI_TYPE_GEOMETRY,
    ];

    /**
     * @param  Closure(string): void  $write
     */
    public function __construct(
        private readonly Client $client,
        private readonly Catalog $catalog,
        private readonly Closure $write,
    ) {}

    /**
     * Dump tables (all of them when none are named) as SQL.
     *
     * @param  list<string>  $tables  resolved table names, or [] for all
     */
    public function sql(string $database, array $tables, bool $structure, bool $data): void
    {
        if (! $structure && ! $data) {
            throw new UserError('Choose structure, data, or both.');
        }

        $all = $this->catalog->tables($database);
        $selected = $tables === [] ? $all : array_values(array_filter($all, static fn (array $table): bool => in_array($table['name'], $tables, true)));

        $baseTables = array_values(array_filter($selected, static fn (array $table): bool => ! $table['view']));
        $views = array_values(array_filter($selected, static fn (array $table): bool => $table['view']));

        $this->client->query("SET SESSION time_zone = '+00:00'");
        $this->client->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->client->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $this->write(implode("\n", [
            '-- DB Admin SQL dump',
            '-- Server: '.$this->client->version(),
            '-- Database: '.$database,
            '-- Generated: '.gmdate('Y-m-d H:i:s').' UTC',
            '',
            'SET NAMES utf8mb4;',
            'SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;',
            'SET @OLD_UNIQUE_CHECKS = @@UNIQUE_CHECKS, UNIQUE_CHECKS = 0;',
            "SET @OLD_SQL_MODE = @@SQL_MODE, SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
            "SET @OLD_TIME_ZONE = @@TIME_ZONE, TIME_ZONE = '+00:00';",
            '',
            '',
        ]));

        foreach ($baseTables as $table) {
            $name = $table['name'];

            if ($structure) {
                $this->write(sprintf(
                    "--\n-- Table %1\$s\n--\n\nDROP TABLE IF EXISTS %2\$s;\n%3\$s;\n\n",
                    $name,
                    Identifier::quote($name),
                    $this->catalog->createStatement($database, $name),
                ));
            }

            if ($data) {
                $this->tableData($database, $name);
            }

            if ($structure) {
                $this->triggers($database, $name);
            }
        }

        if ($structure) {
            foreach (self::viewOrder($views, fn (string $name): string => $this->catalog->createStatement($database, $name)) as $name => $create) {
                $this->write(sprintf(
                    "--\n-- View %1\$s\n--\n\nDROP VIEW IF EXISTS %2\$s;\n%3\$s;\n\n",
                    $name,
                    Identifier::quote($name),
                    self::withoutDefiner($create),
                ));
            }

            // A whole database takes its procedures, functions and events
            // along; a few chosen tables do not.
            if ($tables === []) {
                $this->write((new Objects($this->client, $this->catalog))->dump($database));
            }
        }

        $this->client->query('COMMIT');

        $this->write(implode("\n", [
            'SET TIME_ZONE = @OLD_TIME_ZONE;',
            'SET SQL_MODE = @OLD_SQL_MODE;',
            'SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS;',
            'SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;',
            '',
            '-- Dump completed '.gmdate('Y-m-d H:i:s').' UTC',
            '',
        ]));
    }

    /**
     * A table's rows as CSV, optionally only those matching a condition.
     */
    public function csvTable(string $database, string $table, string $where = ''): void
    {
        $columns = array_map(
            static fn (array $column): string => $column['dataType'] === 'bit' ? Identifier::quote($column['name']).' + 0 AS '.Identifier::quote($column['name']) : Identifier::quote($column['name']),
            $this->catalog->columns($database, $table),
        );

        $this->csv(sprintf(
            'SELECT %s FROM %s%s',
            implode(', ', $columns),
            Identifier::qualified($database, $table),
            $where === '' ? '' : ' WHERE '.$where,
        ));
    }

    /**
     * The result of one reading statement as CSV.
     */
    public function csvQuery(?string $database, string $sql): void
    {
        $statements = Splitter::split($sql);

        if (count($statements) !== 1) {
            throw new UserError('Export one statement at a time.');
        }

        if (! Analyzer::analyze($statements[0]->sql)['readOnly']) {
            throw new UserError('Only a statement that reads, such as SELECT, can be exported.');
        }

        if ($database !== null) {
            $this->client->useDatabase($database);
        }

        $this->csv($statements[0]->sql);
    }

    private function csv(string $sql): void
    {
        $result = $this->unbuffered($sql);

        if (! $result instanceof mysqli_result) {
            throw new UserError('That statement returns no rows to export.');
        }

        $this->write(self::csvLine(array_map(static fn (object $field): string => (string) $field->name, $result->fetch_fields())));

        $buffer = '';

        while (($row = $result->fetch_row()) !== null && $row !== false) {
            $buffer .= self::csvLine($row);

            if (strlen($buffer) >= 65536) {
                $this->write($buffer);
                $buffer = '';
            }
        }

        $result->free();
        $this->write($buffer);
    }

    /**
     * One CSV line: fields quoted when they need to be, NULL as an empty
     * unquoted field and an empty string as "".
     *
     * @param  list<?string>  $fields
     */
    public static function csvLine(array $fields): string
    {
        return implode(',', array_map(static function (?string $field): string {
            if ($field === null) {
                return '';
            }

            return $field === '' || strpbrk($field, ",\"\r\n") !== false ? '"'.str_replace('"', '""', $field).'"' : $field;
        }, $fields))."\r\n";
    }

    private function tableData(string $database, string $table): void
    {
        $columns = array_values(array_filter(
            $this->catalog->columns($database, $table),
            static fn (array $column): bool => stripos($column['extra'], 'generated') === false || stripos($column['extra'], 'default_generated') !== false,
        ));

        if ($columns === []) {
            return;
        }

        $select = array_map(
            static fn (array $column): string => $column['dataType'] === 'bit' ? Identifier::quote($column['name']).' + 0' : Identifier::quote($column['name']),
            $columns,
        );

        $result = $this->unbuffered(sprintf('SELECT %s FROM %s', implode(', ', $select), Identifier::qualified($database, $table)));

        if (! $result instanceof mysqli_result) {
            return;
        }

        $fields = $result->fetch_fields();
        $binary = array_map(static fn (object $field): bool => (int) $field->charsetnr === 63 && in_array((int) $field->type, self::BINARY_FIELD_TYPES, true), $fields);
        $numeric = array_map(static fn (object $field): bool => ColumnType::isNumeric((int) $field->type), $fields);

        $head = sprintf(
            'INSERT INTO %s (%s) VALUES',
            Identifier::quote($table),
            implode(', ', array_map(static fn (array $column): string => Identifier::quote($column['name']), $columns)),
        );

        $statement = '';
        $rows = 0;

        while (($row = $result->fetch_row()) !== null && $row !== false) {
            $values = [];

            foreach ($row as $index => $value) {
                $values[] = match (true) {
                    $value === null => 'NULL',
                    $numeric[$index] && is_numeric($value) => (string) $value,
                    $binary[$index] => $value === '' ? "''" : '0x'.bin2hex((string) $value),
                    default => $this->client->quote((string) $value),
                };
            }

            $tuple = '('.implode(',', $values).')';

            if ($statement !== '' && strlen($statement) + strlen($tuple) > self::INSERT_BYTES) {
                $this->write($statement.";\n");
                $statement = '';
            }

            $statement .= $statement === '' ? $head."\n".$tuple : ",\n".$tuple;
            $rows++;
        }

        $result->free();

        if ($statement !== '') {
            $this->write($statement.";\n");
        }

        if ($rows > 0) {
            $this->write("\n");
        }
    }

    private function triggers(string $database, string $table): void
    {
        foreach ($this->catalog->triggers($database, $table) as $trigger) {
            $rows = $this->client->select('SHOW CREATE TRIGGER '.Identifier::qualified($database, $trigger['name']));
            $create = (string) ($rows[0]['SQL Original Statement'] ?? '');

            if ($create !== '') {
                $this->write(sprintf("DELIMITER ;;\n%s;;\nDELIMITER ;\n\n", self::withoutDefiner($create)));
            }
        }
    }

    /**
     * Order views so that one using another comes after it.
     *
     * @param  list<array{name: string}>  $views
     * @param  Closure(string): string  $create
     * @return array<string, string>  name => CREATE VIEW statement
     */
    public static function viewOrder(array $views, Closure $create): array
    {
        $pending = [];

        foreach ($views as $view) {
            $pending[$view['name']] = $create($view['name']);
        }

        $ordered = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $name => $statement) {
                $dependsOnPending = false;

                foreach (array_keys($pending) as $other) {
                    if ($other !== $name && str_contains($statement, Identifier::quote((string) $other))) {
                        $dependsOnPending = true;

                        break;
                    }
                }

                if (! $dependsOnPending) {
                    $ordered[$name] = $statement;
                    unset($pending[$name]);
                    $progress = true;
                }
            }

            // A cycle can only be a false match; keep the rest in name order.
            if (! $progress) {
                $ordered += $pending;

                break;
            }
        }

        return $ordered;
    }

    /**
     * Drop the DEFINER clause, so the object is created as the importing user.
     */
    public static function withoutDefiner(string $create): string
    {
        return (string) preg_replace('/\sDEFINER\s*=\s*(?:`(?:[^`]|``)*`|\S+?)@(?:`(?:[^`]|``)*`|\S+)(?=\s)/i', '', $create, 1);
    }

    private function unbuffered(string $sql): mysqli_result|bool
    {
        try {
            return $this->client->mysqli()->query($sql, MYSQLI_USE_RESULT);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }
    }

    private function write(string $bytes): void
    {
        if ($bytes !== '') {
            ($this->write)($bytes);
        }
    }
}
