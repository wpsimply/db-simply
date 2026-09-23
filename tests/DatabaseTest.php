<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use DbSimply\Console;
use DbSimply\Rows;
use DbSimply\UserError;

/**
 * The catalog, the row browser and the console against a real server.
 */
final class DatabaseTest extends TestCase
{
    private const string POSTS = "CREATE TABLE wp_posts (
        ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_title TEXT NOT NULL,
        post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
        post_content LONGTEXT,
        KEY status (post_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    private const string OPTIONS = 'CREATE TABLE wp_options (
        option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        option_name VARCHAR(191) NOT NULL,
        option_value LONGTEXT NOT NULL,
        PRIMARY KEY (option_id),
        UNIQUE KEY option_name (option_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    public function testCatalogListsOnlyWhatTheUserCanSeeAndIsNotHidden(): void
    {
        $catalog = $this->catalog($this->client(self::POSTS));

        self::assertTrue(in_array($this->database(), $catalog->databaseNames(), true));
        self::assertTrue(! in_array('information_schema', $catalog->databaseNames(), true));
        self::assertThrows(UserError::class, fn () => $catalog->database('information_schema'));
        self::assertThrows(UserError::class, fn () => $catalog->database('mysql'));
        self::assertSame(['wp_posts'], array_column($catalog->tables($this->database()), 'name'));
        self::assertThrows(UserError::class, fn () => $catalog->table($this->database(), 'WP_POSTS'));
        self::assertThrows(UserError::class, fn () => $catalog->table($this->database(), 'wp_posts` WHERE 1 --'));
    }

    public function testStructureDescribesColumnsIndexesAndForeignKeys(): void
    {
        $catalog = $this->catalog($this->client(
            self::POSTS,
            'CREATE TABLE wp_comments (
                comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                comment_post_ID BIGINT UNSIGNED NOT NULL,
                CONSTRAINT post FOREIGN KEY (comment_post_ID) REFERENCES wp_posts (ID) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        ));
        $db = $this->database();

        $columns = $catalog->columns($db, 'wp_posts');
        self::assertSame(['ID', 'post_title', 'post_status', 'post_content'], array_column($columns, 'name'));
        // MySQL 8 no longer reports the display width MariaDB does.
        self::assertTrue(in_array(strtolower($columns[0]['type']), ['bigint(20) unsigned', 'bigint unsigned'], true));

        $indexes = array_column($catalog->indexes($db, 'wp_posts'), null, 'name');
        self::assertTrue($indexes['PRIMARY']['primary'] && $indexes['PRIMARY']['unique']);
        self::assertSame(false, $indexes['status']['unique']);

        $keys = $catalog->foreignKeys($db, 'wp_comments');
        self::assertSame('wp_posts', $keys[0]['referencedTable']);
        self::assertSame(['comment_post_ID'], $keys[0]['columns']);
        self::assertSame('CASCADE', $keys[0]['onDelete']);

        self::assertTrue(str_starts_with($catalog->createStatement($db, 'wp_posts'), 'CREATE TABLE `wp_posts`'));
    }

    public function testRowsArePagedSortedFilteredAndSearched(): void
    {
        $client = $this->client(self::POSTS);

        for ($i = 1; $i <= 12; $i++) {
            $client->query(sprintf("INSERT INTO wp_posts (post_title, post_status) VALUES ('Post %d', '%s')", $i, $i % 3 === 0 ? 'draft' : 'publish'));
        }

        $rows = $this->rows($client);
        $db = $this->database();

        $page = $rows->page($db, 'wp_posts', ['offset' => 10, 'limit' => 5]);
        self::assertSame(['ID'], $page['key']);
        self::assertSame(['11', '12'], array_column($page['rows'], 0));
        self::assertSame(12, $page['total']);
        self::assertSame(false, $page['hasMore']);

        $page = $rows->page($db, 'wp_posts', ['limit' => 3, 'sort' => 'ID', 'direction' => 'desc']);
        self::assertSame(['12', '11', '10'], array_column($page['rows'], 0));
        self::assertSame(true, $page['hasMore']);

        $page = $rows->page($db, 'wp_posts', ['filters' => [['column' => 'post_status', 'operator' => '=', 'value' => 'draft']]]);
        self::assertSame(4, $page['total']);

        $page = $rows->page($db, 'wp_posts', ['search' => 'Post 1']);
        self::assertSame(['1', '10', '11', '12'], array_column($page['rows'], 0));

        // Wildcards in a search are matched literally.
        self::assertSame(0, $rows->page($db, 'wp_posts', ['search' => 'Post_%'])['total']);

        self::assertThrows(UserError::class, fn () => $rows->page($db, 'wp_posts', ['sort' => 'ID; DROP TABLE wp_posts']));
        self::assertThrows(UserError::class, fn () => $rows->page($db, 'wp_posts', ['filters' => [['column' => 'ID', 'operator' => 'OR 1=1']]]));
    }

    public function testLongValuesArePreviewedAndReadInFullByRowKey(): void
    {
        $client = $this->client(self::OPTIONS);
        $long = str_repeat('é', 2000);
        $serialized = serialize(['home' => 'https://example.com']);

        $client->query(sprintf("INSERT INTO wp_options (option_name, option_value) VALUES ('long', %s), ('widgets', %s)", $client->quote($long), $client->quote($serialized)));

        $rows = $this->rows($client);
        $db = $this->database();
        $page = $rows->page($db, 'wp_options', []);

        self::assertSame(strlen($long), $page['rows'][0][2]['len']);
        self::assertTrue(strlen($page['rows'][0][2]['$t']) <= 1024);

        $value = $rows->value($db, 'wp_options', 'option_value', ['option_id' => '1']);
        self::assertSame($long, $value['value']);

        $value = $rows->value($db, 'wp_options', 'option_value', ['option_id' => '2']);
        self::assertSame('serialized', $value['format']);
        self::assertTrue(str_contains((string) $value['pretty'], '"home": "https://example.com"'));

        self::assertThrows(UserError::class, fn () => $rows->value($db, 'wp_options', 'option_value', ['option_id' => '99']), 'no longer exists');
    }

    public function testConsoleRunsStatementsInOrderAndStopsAtTheFirstError(): void
    {
        $client = $this->client(self::POSTS);
        $console = new Console($client, 5, 1024);

        $result = $console->run(
            "INSERT INTO wp_posts (post_title) VALUES ('a'), ('b');\nSELECT post_title FROM wp_posts ORDER BY ID;\nSELECT nope FROM wp_posts;\nSELECT 1;",
            $this->database(),
            false,
            false,
        );

        self::assertSame(3, count($result['results']));
        self::assertSame(2, $result['results'][0]['sets'][0]['affected']);
        self::assertSame([['a'], ['b']], $result['results'][1]['sets'][0]['rows']);
        self::assertSame('post_title', $result['results'][1]['sets'][0]['columns'][0]['name']);
        self::assertSame(3, $result['results'][2]['line']);
        self::assertTrue(str_contains($result['results'][2]['error'], 'nope'));
    }

    public function testConsoleCutsLongResultsOff(): void
    {
        $client = $this->client(self::POSTS);
        $client->query("INSERT INTO wp_posts (post_title) VALUES ".implode(', ', array_map(static fn (int $i): string => "('p{$i}')", range(1, 20))));

        $set = (new Console($client, 5, 1024))->run('SELECT * FROM wp_posts', $this->database(), false, false)['results'][0]['sets'][0];

        self::assertSame(5, count($set['rows']));
        self::assertSame(true, $set['truncated']);
    }

    public function testConsoleAsksBeforeDestroyingData(): void
    {
        $client = $this->client(self::POSTS);
        $console = new Console($client, 100, 1024);

        $result = $console->run("SELECT 1;\nDELETE FROM wp_posts;\nDROP TABLE wp_posts;", $this->database(), false, false);

        self::assertSame(['confirm'], array_keys($result));
        self::assertSame([2, 3], array_column($result['confirm'], 'line'));
        self::assertSame(1, count($this->catalog($client)->tables($this->database())), 'Nothing may run before confirmation.');

        $console->run('DROP TABLE wp_posts', $this->database(), false, true);
        self::assertSame([], $this->catalog($client)->tables($this->database()));
    }

    public function testReadOnlySessionsCannotWrite(): void
    {
        $client = $this->client(self::POSTS);
        $console = new Console($client, 100, 1024);

        self::assertThrows(UserError::class, fn () => $console->run('INSERT INTO wp_posts (post_title) VALUES (1)', $this->database(), true, false), 'read-only');

        $result = $console->run('SELECT COUNT(*) FROM wp_posts', $this->database(), true, false);
        self::assertSame([['0']], $result['results'][0]['sets'][0]['rows']);

        // A write that slips past the analyser still meets a read-only transaction.
        $result = $console->run('SELECT 1', $this->database(), true, false);
        self::assertTrue(! isset($result['results'][0]['error']));
        self::assertThrows(UserError::class, fn () => $client->query("INSERT INTO wp_posts (post_title) VALUES ('x')"));
    }

    public function testLocalInfileIsRefused(): void
    {
        $client = $this->client('CREATE TABLE t (a TEXT)');
        $result = (new Console($client, 100, 1024))->run("LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t", $this->database(), false, false);

        self::assertTrue(isset($result['results'][0]['error']));
        self::assertSame('0', $client->value('SELECT COUNT(*) FROM t'));
    }

    private function rows(\DbSimply\Client $client): Rows
    {
        return new Rows($client, $this->catalog($client), 1024, 1048576, 100000);
    }
}
