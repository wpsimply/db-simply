<?php

declare(strict_types=1);

namespace DbSimply\Sql;

/**
 * One statement cut out of SQL text by the {@see Splitter}.
 */
final readonly class Statement
{
    /**
     * @param  string  $sql  the statement, without its delimiter
     * @param  int  $line  the line it starts on
     * @param  int  $endOffset  the byte offset right after its delimiter
     * @param  int  $endLine  the line that offset is on
     * @param  string  $delimiter  the delimiter in force after it
     */
    public function __construct(
        public string $sql,
        public int $line,
        public int $endOffset,
        public int $endLine,
        public string $delimiter,
    ) {}
}
