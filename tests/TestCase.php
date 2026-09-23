<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use Closure;
use DbSimply\Catalog;
use DbSimply\Client;
use DbSimply\Config;
use DbSimply\Connection;
use RuntimeException;
use Throwable;

final class Skipped extends RuntimeException {}

final class AssertionFailed extends RuntimeException {}

abstract class TestCase
{
    protected ?Client $client = null;

    public function setUp(): void {}

    public function tearDown(): void
    {
        $this->client?->close();
    }

    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.'));
        }
    }

    protected static function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (! $condition) {
            throw new AssertionFailed($message);
        }
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    protected static function assertThrows(string $class, Closure $callback, ?string $messageContains = null): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (! $e instanceof $class) {
                throw new AssertionFailed(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            }

            if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed(sprintf('Expected the message to contain "%s", got "%s".', $messageContains, $e->getMessage()));
            }

            return $e;
        }

        throw new AssertionFailed(sprintf('Expected %s to be thrown.', $class));
    }

    /**
     * The test database's connection details, or a skip when none are configured.
     *
     * @return array{host: string, port: int, user: string, password: string, database: string}
     */
    protected function target(): array
    {
        $host = getenv('DB_SIMPLY_TEST_HOST') ?: null;

        if ($host === null) {
            throw new Skipped('No test database configured.');
        }

        return [
            'host' => $host,
            'port' => (int) (getenv('DB_SIMPLY_TEST_PORT') ?: 3306),
            'user' => getenv('DB_SIMPLY_TEST_USER') ?: 'root',
            'password' => getenv('DB_SIMPLY_TEST_PASSWORD') ?: '',
            'database' => getenv('DB_SIMPLY_TEST_DATABASE') ?: 'dbsimply_test',
        ];
    }

    /**
     * A config pointing at the test server.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function config(array $overrides = []): Config
    {
        $target = $this->target();

        return Config::fromArray(dirname(__DIR__), array_replace_recursive([
            'db' => ['host' => $target['host'], 'port' => $target['port']],
        ], $overrides));
    }

    /**
     * A client on an emptied test database, with the given tables created.
     */
    protected function client(string ...$statements): Client
    {
        $target = $this->target();
        $this->client = (new Connection($this->config()))->open(['user' => $target['user'], 'password' => $target['password']]);
        $this->client->useDatabase($target['database']);

        $this->client->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->client->select("SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") as $row) {
            $this->client->query(($row['type'] === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ').'`'.str_replace('`', '``', (string) $row['name']).'`');
        }

        foreach ($this->client->select('SELECT ROUTINE_TYPE AS type, ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()') as $row) {
            $this->client->query('DROP '.$row['type'].' `'.str_replace('`', '``', (string) $row['name']).'`');
        }

        foreach ($this->client->select('SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()') as $row) {
            $this->client->query('DROP EVENT `'.str_replace('`', '``', (string) $row['name']).'`');
        }

        $this->client->query('SET FOREIGN_KEY_CHECKS = 1');

        foreach ($statements as $statement) {
            $this->client->query($statement);
        }

        return $this->client;
    }

    protected function catalog(Client $client): Catalog
    {
        return new Catalog($client, ['information_schema', 'performance_schema', 'mysql', 'sys']);
    }

    protected function database(): string
    {
        return $this->target()['database'];
    }

    protected static function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/db-simply-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }
}
