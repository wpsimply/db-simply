<?php

declare(strict_types=1);

namespace DbSimply\Sql;

/**
 * Splits SQL text into statements, the way the mysql command-line client does.
 *
 * A delimiter only ends a statement outside string literals ('…', "…"),
 * quoted identifiers (`…`) and comments (-- …, # …, /* … *\/). `DELIMITER x`
 * on a line of its own changes the delimiter, as dumps with stored routines
 * and triggers do, and is not itself sent to the server. Executable comments
 * (/*! … *\/, /*M! … *\/) count as statement text.
 *
 * Text can be fed in chunks of any size, so a dump is split while it is read
 * rather than after it has been loaded whole. Every statement records the
 * byte offset, line and delimiter right after it, which is exactly the state
 * needed to resume splitting the same input from there in a later request.
 */
final class Splitter
{
    private const int NORMAL = 0;

    private const int SINGLE_QUOTE = 1;

    private const int DOUBLE_QUOTE = 2;

    private const int BACKTICK = 3;

    private const int LINE_COMMENT = 4;

    private const int BLOCK_COMMENT = 5;

    private string $buffer = '';

    /**
     * Where scanning continues, in the buffer.
     */
    private int $position = 0;

    /**
     * Where the current statement starts, in the buffer.
     */
    private int $start = 0;

    /**
     * The absolute offset of the buffer's first byte.
     */
    private int $base;

    private int $line;

    private int $state = self::NORMAL;

    /**
     * Whether the current statement has anything but whitespace and comments.
     */
    private bool $significant = false;

    private ?int $statementLine = null;

    /**
     * Whether only whitespace has been seen on the current line so far, which
     * is where a DELIMITER command may start.
     */
    private bool $lineBlank = true;

    public function __construct(private string $delimiter = ';', int $offset = 0, int $line = 1)
    {
        $this->base = $offset;
        $this->line = $line;
    }

    /**
     * Split a complete piece of SQL.
     *
     * @return list<Statement>
     */
    public static function split(string $sql, string $delimiter = ';'): array
    {
        $splitter = new self($delimiter);

        return [...$splitter->feed($sql), ...$splitter->finish()];
    }

    /**
     * Add text and return the statements it completes.
     *
     * @return list<Statement>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;

        return $this->drain(false);
    }

    /**
     * Mark the end of the input and return what is left: a last statement
     * with no delimiter after it, if there is one.
     *
     * @return list<Statement>
     */
    public function finish(): array
    {
        $statements = $this->drain(true);

        if ($this->significant) {
            $statements[] = $this->emit(strlen($this->buffer), 0);
        }

        return $statements;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * @return list<Statement>
     */
    private function drain(bool $final): array
    {
        $statements = [];
        $length = strlen($this->buffer);

        while ($this->position < $length) {
            $char = $this->buffer[$this->position];

            switch ($this->state) {
                case self::NORMAL:
                    // A DELIMITER command: only before a statement has begun,
                    // and only at the start of a line.
                    if (! $this->significant && $this->lineBlank && ($char === 'd' || $char === 'D')) {
                        $handled = $this->delimiterCommand($final);

                        if ($handled === null) {
                            break 2;
                        }

                        if ($handled) {
                            $length = strlen($this->buffer);

                            continue 2;
                        }
                    }

                    if ($char === $this->delimiter[0]) {
                        $delimiterLength = strlen($this->delimiter);

                        if ($this->position + $delimiterLength > $length && ! $final
                            && str_starts_with($this->delimiter, substr($this->buffer, $this->position))) {
                            break 2;
                        }

                        if (substr_compare($this->buffer, $this->delimiter, $this->position, $delimiterLength) === 0) {
                            if ($this->significant) {
                                $statements[] = $this->emit($this->position, $delimiterLength);
                            } else {
                                // An empty statement, or one of comments only: dropped.
                                $this->position += $delimiterLength;
                                $this->start = $this->position;
                            }

                            $this->lineBlank = false;

                            continue 2;
                        }
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $this->markSignificant();
                        $this->state = match ($char) {
                            "'" => self::SINGLE_QUOTE,
                            '"' => self::DOUBLE_QUOTE,
                            default => self::BACKTICK,
                        };
                        $this->position++;
                        $this->lineBlank = false;

                        break;
                    }

                    if ($char === '#') {
                        $this->state = self::LINE_COMMENT;
                        $this->position++;
                        $this->lineBlank = false;

                        break;
                    }

                    if ($char === '-' || $char === '/') {
                        $comment = $this->commentStart($final);

                        if ($comment === null) {
                            break 2;
                        }

                        if ($comment !== false) {
                            $this->lineBlank = false;

                            break;
                        }
                    }

                    if ($char === "\n") {
                        $this->line++;
                        $this->position++;
                        $this->lineBlank = true;

                        break;
                    }

                    // A run of ordinary characters, consumed at once.
                    $run = max(1, strcspn($this->buffer, "'\"`#-/\nDd".$this->delimiter[0], $this->position + 1) + 1);
                    $text = substr($this->buffer, $this->position, $run);

                    if (strspn($text, " \t\r\f\v") < strlen($text)) {
                        $this->markSignificant();
                        $this->lineBlank = false;
                    }

                    $this->position += $run;

                    break;

                case self::SINGLE_QUOTE:
                case self::DOUBLE_QUOTE:
                    $quote = $this->state === self::SINGLE_QUOTE ? "'" : '"';
                    $skip = strcspn($this->buffer, $quote."\\\n", $this->position);
                    $this->position += $skip;

                    if ($this->position >= $length) {
                        break;
                    }

                    $char = $this->buffer[$this->position];

                    if ($char === "\n") {
                        $this->line++;
                        $this->position++;
                    } elseif ($char === '\\') {
                        if ($this->position + 1 >= $length && ! $final) {
                            break 2;
                        }

                        if (($this->buffer[$this->position + 1] ?? '') === "\n") {
                            $this->line++;
                        }

                        $this->position += 2;
                    } else {
                        if ($this->position + 1 >= $length && ! $final) {
                            break 2;
                        }

                        // A doubled quote is a quote inside the literal.
                        if (($this->buffer[$this->position + 1] ?? '') === $quote) {
                            $this->position += 2;
                        } else {
                            $this->position++;
                            $this->state = self::NORMAL;
                        }
                    }

                    break;

                case self::BACKTICK:
                    $skip = strcspn($this->buffer, "`\n", $this->position);
                    $this->position += $skip;

                    if ($this->position >= $length) {
                        break;
                    }

                    if ($this->buffer[$this->position] === "\n") {
                        $this->line++;
                        $this->position++;
                    } elseif ($this->position + 1 >= $length && ! $final) {
                        break 2;
                    } elseif (($this->buffer[$this->position + 1] ?? '') === '`') {
                        $this->position += 2;
                    } else {
                        $this->position++;
                        $this->state = self::NORMAL;
                    }

                    break;

                case self::LINE_COMMENT:
                    // The newline itself is left for NORMAL to count.
                    $this->position += strcspn($this->buffer, "\n", $this->position);

                    if ($this->position < $length) {
                        $this->state = self::NORMAL;
                    }

                    break;

                case self::BLOCK_COMMENT:
                    $end = strpos($this->buffer, '*/', $this->position);

                    if ($end === false) {
                        // Keep a trailing "*" for the next chunk to complete.
                        $until = str_ends_with($this->buffer, '*') && ! $final ? $length - 1 : $length;
                        $this->line += substr_count($this->buffer, "\n", $this->position, $until - $this->position);
                        $this->position = $until;

                        break 2;
                    }

                    $this->line += substr_count($this->buffer, "\n", $this->position, $end - $this->position);
                    $this->position = $end + 2;
                    $this->state = self::NORMAL;

                    break;
            }
        }

        $this->compact();

        return $statements;
    }

    /**
     * At "-" or "/": enter a comment if one starts here.
     *
     * @return bool|null true when a comment started, false when this is an
     *                   ordinary character, null when more input is needed
     */
    private function commentStart(bool $final): ?bool
    {
        $length = strlen($this->buffer);
        $at = $this->position;
        $next = $this->buffer[$at + 1] ?? null;

        if ($next === null) {
            return $final ? false : null;
        }

        if ($this->buffer[$at] === '-') {
            if ($next !== '-') {
                return false;
            }

            // "--" opens a comment only when followed by whitespace, a control
            // character, or the end of the input.
            $after = $this->buffer[$at + 2] ?? null;

            if ($after === null && ! $final) {
                return null;
            }

            if ($after !== null && ord($after) > 32) {
                return false;
            }

            $this->state = self::LINE_COMMENT;
            $this->position += 2;

            return true;
        }

        if ($next !== '*') {
            return false;
        }

        if ($at + 3 >= $length && ! $final) {
            return null;
        }

        // An executable comment is statement text as far as splitting goes.
        if (($this->buffer[$at + 2] ?? '') === '!' || substr($this->buffer, $at + 2, 2) === 'M!') {
            $this->markSignificant();
        }

        $this->state = self::BLOCK_COMMENT;
        $this->position += 2;

        return true;
    }

    /**
     * At a "d" at the start of a line, before any statement: handle a
     * DELIMITER command if this is one.
     *
     * @return bool|null true when one was handled, false when this is not one,
     *                   null when more input is needed to tell
     */
    private function delimiterCommand(bool $final): ?bool
    {
        $end = strpos($this->buffer, "\n", $this->position);

        if ($end === false && ! $final) {
            // Enough to rule it out already?
            $head = substr($this->buffer, $this->position, 10);

            return strncasecmp($head, 'delimiter ', strlen($head)) === 0 || strncasecmp($head, "delimiter\t", strlen($head)) === 0 ? null : false;
        }

        $lineEnd = $end === false ? strlen($this->buffer) : $end;
        $text = rtrim(substr($this->buffer, $this->position, $lineEnd - $this->position), "\r \t");

        if (preg_match('/^delimiter[ \t]+(\S+)/i', $text, $match) !== 1) {
            return false;
        }

        $this->delimiter = $match[1];
        $this->position = $end === false ? $lineEnd : $lineEnd + 1;
        $this->start = $this->position;

        if ($end !== false) {
            $this->line++;
        }

        $this->lineBlank = true;

        return true;
    }

    private function markSignificant(): void
    {
        if (! $this->significant) {
            $this->significant = true;
            $this->statementLine = $this->line;
        }
    }

    private function emit(int $end, int $delimiterLength): Statement
    {
        $statement = new Statement(
            sql: trim(substr($this->buffer, $this->start, $end - $this->start)),
            line: $this->statementLine ?? $this->line,
            endOffset: $this->base + $end + $delimiterLength,
            endLine: $this->line,
            delimiter: $this->delimiter,
        );

        $this->position = $end + $delimiterLength;
        $this->start = $this->position;
        $this->significant = false;
        $this->statementLine = null;

        return $statement;
    }

    /**
     * Drop what has been handed out already, so the buffer holds only the
     * statement in progress.
     */
    private function compact(): void
    {
        if ($this->start === 0) {
            return;
        }

        $this->buffer = (string) substr($this->buffer, $this->start);
        $this->position -= $this->start;
        $this->base += $this->start;
        $this->start = 0;
    }
}
