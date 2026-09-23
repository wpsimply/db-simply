<?php

declare(strict_types=1);

namespace DbAdmin;

use DbAdmin\Sql\Analyzer;
use DbAdmin\Sql\Splitter;
use DbAdmin\Sql\Statement;
use mysqli_sql_exception;

/**
 * Runs an SQL file of any size, a slice at a time.
 *
 * The browser uploads the file in chunks, so no upload limit caps its size.
 * Then each run request executes statements for a few seconds and records
 * where it stopped -- the byte offset, line and delimiter after the last
 * statement that ran, which is all the {@see Splitter} needs to carry on --
 * so no request comes near a timeout, however large the dump.
 *
 * Every request opens a fresh connection, which would lose what a dump sets
 * up for the session at its start (SET NAMES, FOREIGN_KEY_CHECKS = 0, USE …).
 * Those statements are recorded as they run and replayed before the next
 * slice, and each slice ends with a COMMIT, for dumps that switch autocommit
 * off.
 *
 * An import belongs to the session that started it. Its state lives in a
 * JSON file next to the upload, in a directory the web server never serves.
 */
final class Import
{
    private const int READ_BYTES = 1024 * 1024;

    private const int MAX_REPLAY = 100;

    private const int STALE_SECONDS = 86400;

    public function __construct(
        private readonly string $directory,
        private readonly string $owner,
        private readonly int $maxBytes,
    ) {}

    /**
     * Begin an upload.
     *
     * @return array<string, mixed>
     */
    public function start(mixed $name, mixed $size, string $database): array
    {
        $this->prune();

        $size = is_int($size) ? $size : (is_string($size) && ctype_digit($size) ? (int) $size : -1);

        if ($size <= 0) {
            throw new UserError('The file is empty.');
        }

        if ($size > $this->maxBytes) {
            throw new UserError(sprintf('The file is larger than the %s this server accepts.', self::bytes($this->maxBytes)));
        }

        $name = is_string($name) ? mb_substr(basename($name), 0, 200) : 'import.sql';

        if (preg_match('/\.(sql|sql\.gz|gz)$/i', $name) !== 1) {
            throw new UserError('Choose an .sql or .sql.gz file.');
        }

        $id = bin2hex(random_bytes(16));
        $state = [
            'id' => $id,
            'owner' => $this->owner,
            'name' => $name,
            'database' => $database,
            'size' => $size,
            'received' => 0,
            'gzip' => str_ends_with(strtolower($name), '.gz'),
            'state' => 'uploading',
            'sqlSize' => null,
            'offset' => 0,
            'line' => 1,
            'delimiter' => ';',
            'statements' => 0,
            'replay' => [],
            'error' => null,
            'skip' => null,
            'created' => time(),
        ];

        touch($this->path($id, 'upload'));
        chmod($this->path($id, 'upload'), 0600);
        $this->save($state);

        return self::public($state);
    }

    /**
     * Append one uploaded chunk.
     *
     * @return array<string, mixed>
     */
    public function append(mixed $id, mixed $offset, string $bytes): array
    {
        $state = $this->load($id);

        if ($state['state'] !== 'uploading') {
            throw new UserError('This file has been uploaded already.');
        }

        if (! is_numeric($offset) || (int) $offset !== $state['received']) {
            throw new UserError('The upload got out of step. Start it again.', 409);
        }

        if ($bytes === '' || $state['received'] + strlen($bytes) > $state['size']) {
            throw new UserError('The upload is larger than announced.');
        }

        if (file_put_contents($this->path($state['id'], 'upload'), $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
            throw new UserError('The upload could not be stored. The server may be out of disk space.', 507);
        }

        $state['received'] += strlen($bytes);

        if ($state['received'] === $state['size']) {
            $state['state'] = 'ready';
        }

        $this->save($state);

        return self::public($state);
    }

    /**
     * Execute statements for up to the given number of seconds.
     *
     * @return array<string, mixed>
     */
    public function run(mixed $id, Client $client, float $budget): array
    {
        $state = $this->load($id);

        if (! in_array($state['state'], ['ready', 'running'], true)) {
            return self::public($state);
        }

        $lock = fopen($this->path($state['id'], 'lock'), 'c');

        // Another request is already running this import: report, don't race it.
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return [...self::public($state), 'busy' => true];
        }

        try {
            $state = $this->load($id);
            $started = microtime(true);

            if ($state['sqlSize'] === null) {
                $state = $this->prepare($state);
            }

            $state['state'] = 'running';
            $state = $this->execute($state, $client, $started + $budget);
            $this->save($state);

            if ($state['state'] === 'done') {
                $this->remove($state['id']);
            }

            return self::public($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Step past the statement that failed and carry on after it.
     *
     * @return array<string, mixed>
     */
    public function skip(mixed $id): array
    {
        $state = $this->load($id);

        if ($state['state'] !== 'failed' || ! is_array($state['skip'])) {
            throw new UserError('There is no failed statement to skip.');
        }

        $state = [...$state, ...$state['skip'], 'state' => 'running', 'error' => null, 'skip' => null];
        $this->save($state);

        return self::public($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(mixed $id): array
    {
        return self::public($this->load($id));
    }

    public function cancel(mixed $id): void
    {
        $this->remove($this->load($id)['id']);
    }

    /**
     * Turn the upload into the SQL file statements are read from: renamed
     * as it is, or decompressed once when it is gzipped.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function prepare(array $state): array
    {
        $upload = $this->path($state['id'], 'upload');
        $sql = $this->path($state['id'], 'sql');
        $handle = fopen($upload, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 2);

        if ($handle !== false) {
            fclose($handle);
        }

        // Trust the bytes over the name: a .gz that is not gzipped is read as
        // plain SQL, and a gzipped file named .sql is decompressed.
        if ($magic !== "\x1f\x8b") {
            rename($upload, $sql);

            return [...$state, 'sqlSize' => (int) filesize($sql)];
        }

        $in = gzopen($upload, 'rb');
        $out = fopen($sql, 'wb');

        if ($in === false || $out === false) {
            throw new UserError('The file could not be opened.');
        }

        $written = 0;

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, self::READ_BYTES);

                if ($chunk === false) {
                    throw new UserError('The file is not a valid gzip archive.');
                }

                $written += strlen($chunk);

                // A small archive can hold a very large file; a limit on the
                // upload alone would not stop one from filling the disk.
                if ($written > $this->maxBytes * 20) {
                    throw new UserError('The file is too large once decompressed.');
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new UserError('The file could not be decompressed. The server may be out of disk space.', 507);
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }

        unlink($upload);

        return [...$state, 'sqlSize' => $written];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function execute(array $state, Client $client, float $deadline): array
    {
        $mysqli = $client->mysqli();
        $handle = fopen($this->path($state['id'], 'sql'), 'rb');

        if ($handle === false) {
            throw new UserError('The uploaded file is gone. Start the import again.', 410);
        }

        try {
            $client->useDatabase($state['database']);

            foreach ($state['replay'] as $sql) {
                $this->statement($mysqli, $sql);
            }

            fseek($handle, $state['offset']);
            $splitter = new Splitter($state['delimiter'], $state['offset'], $state['line']);

            while (true) {
                $chunk = fread($handle, self::READ_BYTES);
                $end = $chunk === false || $chunk === '' || feof($handle);
                $statements = $splitter->feed((string) $chunk);

                if ($end) {
                    array_push($statements, ...$splitter->finish());
                }

                foreach ($statements as $statement) {
                    try {
                        $this->statement($mysqli, $statement->sql);
                    } catch (mysqli_sql_exception $e) {
                        return [
                            ...$state,
                            'state' => 'failed',
                            'error' => [
                                'line' => $statement->line,
                                'message' => $e->getMessage(),
                                'sql' => mb_strcut($statement->sql, 0, 500, 'UTF-8').(strlen($statement->sql) > 500 ? '…' : ''),
                            ],
                            'skip' => self::position($statement),
                        ];
                    }

                    $state = [...$state, ...self::position($statement), 'statements' => $state['statements'] + 1];
                    $state['replay'] = self::replay($state['replay'], $statement->sql);

                    if (microtime(true) >= $deadline) {
                        return $state;
                    }
                }

                if ($end) {
                    return [...$state, 'state' => 'done', 'offset' => $state['sqlSize']];
                }
            }
        } finally {
            fclose($handle);

            // For dumps that turned autocommit off: what ran in this slice stays.
            try {
                $mysqli->query('COMMIT');
            } catch (mysqli_sql_exception) {
                // Nothing to commit on a broken connection; the error is already reported.
            }
        }
    }

    private function statement(\mysqli $mysqli, string $sql): void
    {
        $mysqli->real_query($sql);

        do {
            $result = $mysqli->store_result();

            if ($result !== false) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
    }

    /**
     * @return array{offset: int, line: int, delimiter: string}
     */
    private static function position(Statement $statement): array
    {
        return ['offset' => $statement->endOffset, 'line' => $statement->endLine, 'delimiter' => $statement->delimiter];
    }

    /**
     * Add a statement to the replay list if it sets up the session.
     *
     * @param  list<string>  $replay
     * @return list<string>
     */
    private static function replay(array $replay, string $sql): array
    {
        if (strlen($sql) > 2000 || ! in_array(Analyzer::analyze($sql)['keyword'], ['SET', 'USE'], true)) {
            return $replay;
        }

        $replay = array_values(array_filter($replay, static fn (string $previous): bool => $previous !== $sql));
        $replay[] = $sql;

        return array_slice($replay, -self::MAX_REPLAY);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(mixed $id): array
    {
        if (! is_string($id) || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new UserError('Unknown import.', 404);
        }

        $state = json_decode((string) @file_get_contents($this->path($id, 'json')), true);

        if (! is_array($state) || ! hash_equals((string) ($state['owner'] ?? ''), $this->owner)) {
            throw new UserError('Unknown import.', 404);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        $file = $this->path($state['id'], 'json');
        $temporary = $file.'.'.bin2hex(random_bytes(4));

        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        chmod($temporary, 0600);
        rename($temporary, $file);
    }

    private function remove(string $id): void
    {
        foreach (['upload', 'sql', 'json', 'lock'] as $extension) {
            @unlink($this->path($id, $extension));
        }
    }

    /**
     * Remove imports nobody came back to finish.
     */
    private function prune(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            if (time() - (int) filemtime($file) > self::STALE_SECONDS) {
                $this->remove(basename($file, '.json'));
            }
        }
    }

    private function path(string $id, string $extension): string
    {
        return $this->directory.'/'.$id.'.'.$extension;
    }

    /**
     * What the browser is told: everything but the owner and the replay list.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function public(array $state): array
    {
        return array_diff_key($state, ['owner' => true, 'replay' => true, 'skip' => true]) + ['canSkip' => is_array($state['skip'] ?? null)];
    }

    private static function bytes(int $size): string
    {
        return $size >= 1073741824 ? round($size / 1073741824, 1).' GB' : round($size / 1048576).' MB';
    }
}
