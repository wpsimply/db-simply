<?php

declare(strict_types=1);

namespace DbAdmin\Sql;

/**
 * Tells what kind of statement a piece of SQL is, without parsing it.
 *
 * It reads the statement's top-level words: the bare words outside string
 * literals, quoted identifiers, comments and parentheses. That is enough to
 * answer the two questions the console asks -- does this only read, and
 * does it throw data away -- and it errs on the careful side: anything it
 * cannot place is treated as a write.
 *
 * This is a guard against mistakes, not a security boundary. What a session
 * can do is decided by the database user's privileges; a read-only session
 * is also held to read-only transactions on the server.
 */
final class Analyzer
{
    private const array READ_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'HELP', 'TABLE', 'VALUES', 'CHECKSUM', 'USE'];

    private const array WRITE_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    /**
     * @return array{keyword: string, readOnly: bool, destructive: ?string}
     */
    public static function analyze(string $sql): array
    {
        [$words, $executableComment] = self::words($sql);
        $keyword = self::mainKeyword($words);

        return [
            'keyword' => $keyword,
            'readOnly' => ! $executableComment && self::isReadOnly($keyword, $words),
            'destructive' => self::destructive($keyword, $words),
        ];
    }

    /**
     * The statement's verb. A WITH clause is looked past, to the statement
     * its common table expressions feed.
     *
     * @param  list<string>  $words
     */
    private static function mainKeyword(array $words): string
    {
        $first = $words[0] ?? '';

        if ($first !== 'WITH') {
            return $first;
        }

        foreach (array_slice($words, 1) as $word) {
            if (in_array($word, ['SELECT', 'TABLE', 'VALUES', ...self::WRITE_KEYWORDS], true)) {
                return $word;
            }
        }

        return $first;
    }

    /**
     * @param  list<string>  $words
     */
    private static function isReadOnly(string $keyword, array $words): bool
    {
        // ANALYZE runs the statement it analyses: ANALYZE SELECT reads,
        // ANALYZE UPDATE writes, and ANALYZE TABLE rewrites statistics.
        if ($keyword === 'ANALYZE') {
            return ($words[1] ?? '') === 'SELECT';
        }

        if (! in_array($keyword, self::READ_KEYWORDS, true)) {
            return false;
        }

        // SELECT … INTO writes a file or variables.
        if (in_array('INTO', $words, true)) {
            return false;
        }

        // EXPLAIN ANALYZE runs what it explains.
        if (in_array($keyword, ['EXPLAIN', 'DESCRIBE', 'DESC'], true) && in_array('ANALYZE', $words, true)) {
            return array_intersect(self::WRITE_KEYWORDS, $words) === [];
        }

        return true;
    }

    /**
     * Why a statement destroys data, or null when it does not.
     *
     * @param  list<string>  $words
     */
    private static function destructive(string $keyword, array $words): ?string
    {
        return match (true) {
            $keyword === 'DROP' => 'drops '.self::object($words[1] ?? '', $words),
            $keyword === 'TRUNCATE' => 'empties a table',
            $keyword === 'DELETE' && ! in_array('WHERE', $words, true) => 'deletes every row of a table',
            $keyword === 'UPDATE' && ! in_array('WHERE', $words, true) => 'changes every row of a table',
            $keyword === 'ALTER' && in_array('DROP', $words, true) => 'drops part of a table',
            default => null,
        };
    }

    /**
     * @param  list<string>  $words
     */
    private static function object(string $word, array $words): string
    {
        $word = in_array($word, ['TEMPORARY', 'OR'], true) ? ($words[2] ?? '') : $word;

        return match ($word) {
            'DATABASE', 'SCHEMA' => 'a database',
            'TABLE', 'TABLES' => 'a table',
            'VIEW' => 'a view',
            'INDEX' => 'an index',
            'PROCEDURE' => 'a procedure',
            'FUNCTION' => 'a function',
            'TRIGGER' => 'a trigger',
            'EVENT' => 'an event',
            'USER' => 'a user',
            default => 'something',
        };
    }

    /**
     * The upper-cased bare words at the top level of the statement, and
     * whether it holds an executable comment.
     *
     * @return array{0: list<string>, 1: bool}
     */
    public static function words(string $sql): array
    {
        $words = [];
        $depth = 0;
        $executableComment = false;
        $inExecutableComment = false;
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                $i = self::skipQuoted($sql, $i, $char);

                continue;
            }

            if ($char === '#' || ($char === '-' && ($sql[$i + 1] ?? '') === '-' && ord($sql[$i + 2] ?? ' ') <= 32)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                // An executable comment's content is SQL: read on into it,
                // past its version number.
                if (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!') {
                    $executableComment = true;
                    $inExecutableComment = true;
                    $i += ($sql[$i + 2] === '!') ? 3 : 4;
                    $i += strspn($sql, '0123456789', $i);

                    continue;
                }

                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($inExecutableComment && $char === '*' && ($sql[$i + 1] ?? '') === '/') {
                $inExecutableComment = false;
                $i += 2;

                continue;
            }

            if ($char === '(') {
                $depth++;
                $i++;

                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $i++;

                continue;
            }

            $run = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', $i);

            if ($run > 0) {
                if ($depth === 0) {
                    $word = strtoupper(substr($sql, $i, $run));

                    // Numbers are not words.
                    if (! ctype_digit($word)) {
                        $words[] = $word;
                    }
                }

                $i += $run;

                continue;
            }

            $i++;
        }

        return [$words, $executableComment];
    }

    private static function skipQuoted(string $sql, int $i, string $quote): int
    {
        $length = strlen($sql);
        $i++;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`') {
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $length;
    }
}
