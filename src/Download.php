<?php

declare(strict_types=1);

namespace DbSimply;

use DeflateContext;

/**
 * Streams a file download to the browser, gzipped on the fly if asked.
 *
 * Headers go out with the first bytes, not before: until something has been
 * written, a failure can still be answered with an error page instead of a
 * broken file.
 */
final class Download
{
    private const int FLUSH_BYTES = 65536;

    private bool $started = false;

    private string $buffer = '';

    private ?DeflateContext $deflate = null;

    public function __construct(
        private readonly string $filename,
        private readonly string $contentType,
        private readonly bool $gzip,
    ) {}

    public function started(): bool
    {
        return $this->started;
    }

    public function write(string $bytes): void
    {
        if (! $this->started) {
            $this->start();
        }

        $this->buffer .= $this->deflate !== null ? (string) deflate_add($this->deflate, $bytes, ZLIB_NO_FLUSH) : $bytes;

        if (strlen($this->buffer) >= self::FLUSH_BYTES) {
            $this->flush();
        }
    }

    public function finish(): void
    {
        if (! $this->started) {
            $this->start();
        }

        if ($this->deflate !== null) {
            $this->buffer .= (string) deflate_add($this->deflate, '', ZLIB_FINISH);
        }

        $this->flush();
    }

    /**
     * A file name safe for a header, from any label.
     */
    public static function filename(string $label, string $extension): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $label), '-.');

        return ($safe === '' ? 'export' : $safe).'.'.$extension;
    }

    private function start(): void
    {
        $this->started = true;
        $name = $this->filename.($this->gzip ? '.gz' : '');

        if ($this->gzip) {
            $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) ?: null;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: '.($this->gzip ? 'application/gzip' : $this->contentType));
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('X-Accel-Buffering: no');
    }

    private function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }

        echo $this->buffer;
        $this->buffer = '';
        flush();
    }
}
