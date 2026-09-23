<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * Carries column values through JSON.
 *
 * A cell is sent to the browser in one of these shapes:
 *
 *   null                            SQL NULL
 *   "text"                          the whole value, valid UTF-8
 *   {"$b64": "..."}                 the whole value, binary
 *   {"$t": "...", "len": 123}       the start of a longer text value
 *   {"$b64": "...", "len": 123}     the start of a longer binary value
 *
 * `len` is the full length in bytes, and its presence is what marks a value
 * as truncated. Values sent back for saving use the first three shapes only.
 */
final class Codec
{
    /**
     * Encode a value read from the database.
     *
     * @param  int|null  $length  the full length in bytes when $bytes is only the start of it
     * @return string|array<string, string|int>|null
     */
    public static function cell(?string $bytes, ?int $length = null): string|array|null
    {
        if ($bytes === null) {
            return null;
        }

        $truncated = $length !== null && $length > strlen($bytes);

        if (self::isText($bytes)) {
            return $truncated ? ['$t' => $bytes, 'len' => $length] : $bytes;
        }

        // A cut can land inside a multi-byte character; that alone must not
        // turn a text value into a binary one.
        if ($truncated && self::isText($trimmed = self::trimPartialCharacter($bytes))) {
            return ['$t' => $trimmed, 'len' => $length];
        }

        return $truncated ? ['$b64' => base64_encode($bytes), 'len' => $length] : ['$b64' => base64_encode($bytes)];
    }

    /**
     * Cut a value to a preview length, returning the kept bytes and the full
     * length, or null for the length when nothing was cut.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function preview(?string $bytes, int $limit): array
    {
        if ($bytes === null || strlen($bytes) <= $limit) {
            return [$bytes, null];
        }

        return [substr($bytes, 0, $limit), strlen($bytes)];
    }

    /**
     * Whether bytes are text a person can read and edit: valid UTF-8 with no
     * control characters other than tab, newline and carriage return.
     */
    public static function isText(string $bytes): bool
    {
        return mb_check_encoding($bytes, 'UTF-8') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes) !== 1;
    }

    /**
     * Decode a value sent by the browser back to bytes: a string, a number,
     * null, or {"$b64": "..."}. A truncated value can never be written back.
     */
    public static function decode(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value) && isset($value['$b64']) && is_string($value['$b64']) && ! isset($value['len'])) {
            $bytes = base64_decode($value['$b64'], true);

            if ($bytes === false) {
                throw new UserError('Invalid base64 value.');
            }

            return $bytes;
        }

        throw new UserError('Expected a complete value.');
    }

    /**
     * A printable rendering of any bytes, for labels: control characters and
     * invalid UTF-8 sequences become \xHH escapes.
     */
    public static function display(string $bytes): string
    {
        return self::isText($bytes) ? $bytes : self::escapeInvalid($bytes);
    }

    /**
     * Drop an incomplete UTF-8 sequence from the end of a cut string.
     */
    private static function trimPartialCharacter(string $bytes): string
    {
        $length = strlen($bytes);

        for ($i = 1; $i <= 3 && $i <= $length; $i++) {
            $byte = ord($bytes[$length - $i]);

            if ($byte < 0x80) {
                return $bytes;
            }

            // The lead byte of a sequence: keep it only if the whole sequence is there.
            if ($byte >= 0xC0) {
                $width = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $width > $i ? substr($bytes, 0, -$i) : $bytes;
            }
        }

        return $bytes;
    }

    /**
     * Escape only the bytes that are not part of a valid UTF-8 sequence.
     */
    private static function escapeInvalid(string $bytes): string
    {
        $out = '';
        $length = strlen($bytes);
        $i = 0;

        while ($i < $length) {
            $char = self::utf8CharAt($bytes, $i);

            if ($char === null || ($char !== "\t" && $char !== "\n" && $char !== "\r" && (ord($char) < 0x20 || $char === "\x7f"))) {
                $out .= sprintf('\\x%02x', ord($bytes[$i]));
                $i++;

                continue;
            }

            $out .= $char;
            $i += strlen($char);
        }

        return $out;
    }

    /**
     * The valid UTF-8 character starting at the given offset, if there is one.
     */
    private static function utf8CharAt(string $bytes, int $offset): ?string
    {
        $first = ord($bytes[$offset]);
        $width = match (true) {
            $first < 0x80 => 1,
            $first >= 0xC2 && $first <= 0xDF => 2,
            $first >= 0xE0 && $first <= 0xEF => 3,
            $first >= 0xF0 && $first <= 0xF4 => 4,
            default => 0,
        };

        if ($width === 0) {
            return null;
        }

        $char = substr($bytes, $offset, $width);

        return strlen($char) === $width && mb_check_encoding($char, 'UTF-8') ? $char : null;
    }
}
