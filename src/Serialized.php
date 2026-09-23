<?php

declare(strict_types=1);

namespace DbSimply;

/**
 * Replaces text inside PHP-serialized values without breaking them.
 *
 * A serialized string records its length in bytes -- s:19:"https://example.com"
 * -- so replacing text inside it with text of another length makes the whole
 * value unreadable to PHP. WordPress keeps much of its data this way (options,
 * post and user meta, widgets), which is why a plain search and replace on a
 * WordPress database breaks sites.
 *
 * This walks the serialized format itself instead of unserializing: nothing
 * is ever instantiated, objects of classes that do not exist here survive
 * untouched, and the output differs from the input only in the strings that
 * held the text and their lengths. A string value that is itself serialized
 * (WordPress double-serializes now and then) is rewritten the same way.
 * Array keys, class names and the raw payload of custom-serialized objects
 * (C:…) are left as they are.
 */
final class Serialized
{
    private const int MAX_DEPTH = 128;

    private string $data;

    private int $position = 0;

    private function __construct(string $data, private readonly string $search, private readonly string $replace, private readonly int $depth)
    {
        $this->data = $data;
    }

    /**
     * Replace text in a value: inside its strings when it is serialized, as
     * plain text otherwise.
     */
    public static function replace(string $value, string $search, string $replace): string
    {
        if ($search === '' || ! str_contains($value, $search)) {
            return $value;
        }

        return self::rewrite($value, $search, $replace, 0) ?? str_replace($search, $replace, $value);
    }

    /**
     * Whether a value is complete, well-formed serialized data.
     */
    public static function isSerialized(string $value): bool
    {
        return self::rewrite($value, "\0", "\0", 0) !== null;
    }

    /**
     * The value with the replacement made inside its strings, or null when
     * it is not serialized data.
     */
    private static function rewrite(string $value, string $search, string $replace, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH || preg_match('/^(?:[aOCE]:\d+:|s:\d+:"|i:-?\d+;|d:|b:[01];|N;)/', $value) !== 1) {
            return null;
        }

        $parser = new self($value, $search, $replace, $depth);

        try {
            $out = $parser->value();
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $parser->position === strlen($value) ? $out : null;
    }

    private function value(): string
    {
        $type = $this->data[$this->position] ?? '';

        return match ($type) {
            'N' => $this->literal('N;'),
            'b' => $this->scalar('/\Gb:[01];/'),
            'i' => $this->scalar('/\Gi:[+-]?\d+;/'),
            'd' => $this->scalar('/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|INF|-INF|NAN);/'),
            'r', 'R' => $this->scalar('/\G[rR]:\d+;/'),
            's' => $this->string(),
            'a' => $this->array(),
            'O' => $this->object(),
            'C' => $this->custom(),
            'E' => $this->enum(),
            default => throw new \UnexpectedValueException('Unknown type.'),
        };
    }

    private function string(): string
    {
        $bytes = $this->lengthPrefixed('s');
        $this->expect(';');

        // A string may hold serialized data of its own.
        $rewritten = self::rewrite($bytes, $this->search, $this->replace, $this->depth + 1)
            ?? ($this->search === '' ? $bytes : str_replace($this->search, $this->replace, $bytes));

        return 's:'.strlen($rewritten).':"'.$rewritten.'";';
    }

    private function array(): string
    {
        $count = $this->count('a');
        $out = 'a:'.$count.':{';

        for ($i = 0; $i < $count; $i++) {
            $out .= $this->key().$this->value();
        }

        $this->expect('}');

        return $out.'}';
    }

    private function object(): string
    {
        $class = $this->lengthPrefixed('O');
        $this->expect(':');
        $count = $this->number();
        $this->expect(':{');
        $out = 'O:'.strlen($class).':"'.$class.'":'.$count.':{';

        for ($i = 0; $i < $count; $i++) {
            $out .= $this->key().$this->value();
        }

        $this->expect('}');

        return $out.'}';
    }

    /**
     * An object serialized by its own Serializable::serialize(): the payload
     * is the class's own format, and is carried over as it is.
     */
    private function custom(): string
    {
        $start = $this->position;
        $this->lengthPrefixed('C');
        $this->expect(':');
        $length = $this->number();
        $this->expect(':{');
        $this->take($length);
        $this->expect('}');

        return substr($this->data, $start, $this->position - $start);
    }

    private function enum(): string
    {
        $start = $this->position;
        $this->lengthPrefixed('E');
        $this->expect(';');

        return substr($this->data, $start, $this->position - $start);
    }

    /**
     * An array key or property name, carried over as it is.
     */
    private function key(): string
    {
        $type = $this->data[$this->position] ?? '';

        if ($type === 'i') {
            return $this->scalar('/\Gi:[+-]?\d+;/');
        }

        if ($type === 's') {
            $bytes = $this->lengthPrefixed('s');
            $this->expect(';');

            return 's:'.strlen($bytes).':"'.$bytes.'";';
        }

        throw new \UnexpectedValueException('Invalid key.');
    }

    /**
     * T:<length>:"<bytes>" -- returns the bytes.
     */
    private function lengthPrefixed(string $type): string
    {
        $length = $this->count($type, ':"');
        $bytes = $this->take($length);
        $this->expect('"');

        return $bytes;
    }

    /**
     * T:<n> followed by the given delimiter -- returns n.
     */
    private function count(string $type, string $then = ':{'): int
    {
        $this->expect($type.':');
        $number = $this->number();
        $this->expect($then);

        return $number;
    }

    private function number(): int
    {
        if (preg_match('/\G\d{1,10}/', $this->data, $match, 0, $this->position) !== 1) {
            throw new \UnexpectedValueException('Expected a number.');
        }

        $this->position += strlen($match[0]);

        return (int) $match[0];
    }

    private function take(int $length): string
    {
        if ($this->position + $length > strlen($this->data)) {
            throw new \UnexpectedValueException('Truncated.');
        }

        $bytes = substr($this->data, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    private function scalar(string $pattern): string
    {
        if (preg_match($pattern, $this->data, $match, 0, $this->position) !== 1) {
            throw new \UnexpectedValueException('Invalid scalar.');
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function literal(string $literal): string
    {
        $this->expect($literal);

        return $literal;
    }

    private function expect(string $text): void
    {
        if ($this->position + strlen($text) > strlen($this->data) || substr_compare($this->data, $text, $this->position, strlen($text)) !== 0) {
            throw new \UnexpectedValueException('Expected '.$text);
        }

        $this->position += strlen($text);
    }
}
