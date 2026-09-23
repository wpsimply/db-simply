<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Names for the column types mysqli reports on a result set.
 */
final class ColumnType
{
    /**
     * The character set number mysqli reports for binary data.
     */
    private const int BINARY_CHARSET = 63;

    private const array NUMERIC = [
        MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG,
        MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_YEAR,
    ];

    public static function isNumeric(int $type): bool
    {
        return in_array($type, self::NUMERIC, true);
    }

    public static function name(int $type, int $charset): string
    {
        $binary = $charset === self::BINARY_CHARSET;

        return match ($type) {
            MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL => 'decimal',
            MYSQLI_TYPE_TINY => 'tinyint',
            MYSQLI_TYPE_SHORT => 'smallint',
            MYSQLI_TYPE_INT24 => 'mediumint',
            MYSQLI_TYPE_LONG => 'int',
            MYSQLI_TYPE_LONGLONG => 'bigint',
            MYSQLI_TYPE_FLOAT => 'float',
            MYSQLI_TYPE_DOUBLE => 'double',
            MYSQLI_TYPE_BIT => 'bit',
            MYSQLI_TYPE_YEAR => 'year',
            MYSQLI_TYPE_DATE, MYSQLI_TYPE_NEWDATE => 'date',
            MYSQLI_TYPE_TIME => 'time',
            MYSQLI_TYPE_DATETIME => 'datetime',
            MYSQLI_TYPE_TIMESTAMP => 'timestamp',
            MYSQLI_TYPE_JSON => 'json',
            MYSQLI_TYPE_ENUM => 'enum',
            MYSQLI_TYPE_SET => 'set',
            MYSQLI_TYPE_GEOMETRY => 'geometry',
            MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB => $binary ? 'blob' : 'text',
            MYSQLI_TYPE_VAR_STRING => $binary ? 'varbinary' : 'varchar',
            MYSQLI_TYPE_STRING => $binary ? 'binary' : 'char',
            MYSQLI_TYPE_NULL => 'null',
            default => 'unknown',
        };
    }
}
