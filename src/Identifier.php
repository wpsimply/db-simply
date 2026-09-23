<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Database, table and column names in SQL.
 *
 * Names reach this app from the browser, so they are never pasted into a
 * statement as they are: each one is checked against what the server itself
 * reports first (see {@see Catalog}), and then quoted here.
 */
final class Identifier
{
    /**
     * Whether a name is one the server could have: 1–64 characters, no NUL.
     */
    public static function isValid(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && mb_check_encoding($name, 'UTF-8')
            && mb_strlen($name) <= 64
            && ! str_contains($name, "\0");
    }

    /**
     * Quote a name with backticks, doubling any backtick inside it.
     */
    public static function quote(string $name): string
    {
        if (! self::isValid($name)) {
            throw new UserError('Invalid name.');
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * Quote a database-qualified name.
     */
    public static function qualified(string $database, string $name): string
    {
        return self::quote($database).'.'.self::quote($name);
    }
}
