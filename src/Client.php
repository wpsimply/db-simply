<?php

declare(strict_types=1);

namespace DbSimply;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * A thin layer over mysqli for the queries the app builds itself.
 *
 * Statements a user types run through {@see Console}, which works with the
 * connection directly. Everything here quotes what it is given, and turns a
 * server error into one the user can read.
 */
final class Client
{
    private ?string $version = null;

    public function __construct(private readonly mysqli $mysqli) {}

    public function mysqli(): mysqli
    {
        return $this->mysqli;
    }

    /**
     * Run a query and return every row as name => value.
     *
     * @return list<array<string, ?string>>
     */
    public function select(string $sql): array
    {
        $result = $this->query($sql);

        if (! $result instanceof mysqli_result) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * Run a query and return every row as a list of values.
     *
     * @return list<list<?string>>
     */
    public function selectRows(string $sql): array
    {
        $result = $this->query($sql);

        if (! $result instanceof mysqli_result) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_NUM);
        $result->free();

        return $rows;
    }

    /**
     * The first column of the first row, or null.
     */
    public function value(string $sql): ?string
    {
        $rows = $this->selectRows($sql);

        return $rows[0][0] ?? null;
    }

    public function query(string $sql): mysqli_result|bool
    {
        try {
            return $this->mysqli->query($sql);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }
    }

    /**
     * Quote a value as a string literal.
     */
    public function quote(?string $value): string
    {
        return $value === null ? 'NULL' : "'".$this->mysqli->real_escape_string($value)."'";
    }

    /**
     * Quote a value for a LIKE pattern, escaping its wildcards.
     */
    public function quoteLike(string $value, string $before = '', string $after = ''): string
    {
        return $this->quote($before.addcslashes($value, '\\%_').$after);
    }

    public function useDatabase(string $database): void
    {
        try {
            $this->mysqli->select_db($database);
        } catch (mysqli_sql_exception $e) {
            throw new UserError($e->getMessage());
        }
    }

    /**
     * The server's version string, e.g. "10.11.6-MariaDB-0+deb12u1".
     */
    public function version(): string
    {
        return $this->version ??= (string) $this->value('SELECT VERSION()');
    }

    public function isMariaDb(): bool
    {
        return stripos($this->version(), 'mariadb') !== false;
    }

    public function close(): void
    {
        $this->mysqli->close();
    }
}
