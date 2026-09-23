<?php

declare(strict_types=1);

namespace DbAdmin\Tests;

use DbAdmin\Objects;
use DbAdmin\Processes;
use DbAdmin\Rows;
use DbAdmin\Schema;
use DbAdmin\Search;
use DbAdmin\UserError;

/**
 * Changing structure, the programmable objects, search and processes.
 */
final class SchemaTest extends TestCase
{
    public function testColumnDefinitionsAreBuiltFromCheckedParts(): void
    {
        self::assertSame(
            "`title` VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'It''s \\\\ new' COMMENT 'The title'",
            Schema::columnDefinition(['name' => 'title', 'type' => 'varchar', 'length' => '191', 'collation' => 'utf8mb4_unicode_ci', 'default' => ['kind' => 'value', 'value' => "It's \\ new"], 'comment' => 'The title']),
        );
        self::assertSame(
            '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            Schema::columnDefinition(['name' => 'id', 'type' => 'BIGINT', 'unsigned' => true, 'autoIncrement' => true]),
        );
        self::assertSame(
            '`price` DECIMAL(10,2) NULL DEFAULT 9.5',
            Schema::columnDefinition(['name' => 'price', 'type' => 'DECIMAL', 'length' => '10, 2', 'nullable' => true, 'default' => ['kind' => 'value', 'value' => '9.5']]),
        );
        self::assertSame(
            "`status` ENUM('a','it''s') NOT NULL DEFAULT 'a'",
            Schema::columnDefinition(['name' => 'status', 'type' => 'ENUM', 'values' => ['a', "it's"], 'default' => ['kind' => 'value', 'value' => 'a']]),
        );
        self::assertSame(
            '`updated` TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)',
            Schema::columnDefinition(['name' => 'updated', 'type' => 'TIMESTAMP', 'length' => '3', 'nullable' => true, 'default' => ['kind' => 'current_timestamp'], 'onUpdateCurrentTimestamp' => true]),
        );

        foreach ([
            ['name' => 'x', 'type' => 'VARCHAR(10); DROP TABLE t; --'],
            ['name' => 'x', 'type' => 'VARCHAR'],
            ['name' => 'x', 'type' => 'VARCHAR', 'length' => '10) NOT NULL, ADD y INT (1'],
            ['name' => 'x', 'type' => 'INT', 'default' => ['kind' => 'value', 'value' => '1; DROP TABLE t']],
            ['name' => 'x', 'type' => 'TEXT', 'default' => ['kind' => 'value', 'value' => 'a']],
            ['name' => 'x', 'type' => 'INT', 'default' => ['kind' => 'current_timestamp']],
            ['name' => 'x', 'type' => 'INT', 'default' => ['kind' => 'null']],
            ['name' => 'x', 'type' => 'VARCHAR', 'length' => '5', 'collation' => 'utf8mb4_bin; DROP'],
            ['name' => 'x', 'type' => 'VARCHAR', 'length' => '5', 'autoIncrement' => true],
            ['name' => '', 'type' => 'INT'],
        ] as $column) {
            self::assertThrows(UserError::class, fn () => Schema::columnDefinition($column));
        }
    }

    public function testColumnsAreReadBackFromEitherServer(): void
    {
        $column = static fn (string $type, ?string $default, bool $nullable = false, string $extra = ''): array => [
            'name' => 'c', 'type' => $type, 'nullable' => $nullable, 'default' => $default, 'extra' => $extra, 'collation' => null, 'comment' => '',
        ];

        // MariaDB quotes literals and writes NULL for DEFAULT NULL.
        self::assertSame(['kind' => 'value', 'value' => "it's"], Schema::describe($column('varchar(20)', "'it''s'"), true)['default']);
        self::assertSame(['kind' => 'null', 'value' => ''], Schema::describe($column('int(11)', 'NULL', true), true)['default']);
        self::assertSame(['kind' => 'none', 'value' => ''], Schema::describe($column('int(11)', null), true)['default']);
        self::assertSame(['kind' => 'current_timestamp', 'value' => ''], Schema::describe($column('datetime', 'current_timestamp()'), true)['default']);

        // MySQL writes literals bare and marks expressions.
        self::assertSame(['kind' => 'value', 'value' => '0'], Schema::describe($column('int', '0'), false)['default']);
        self::assertSame(['kind' => 'current_timestamp', 'value' => ''], Schema::describe($column('timestamp', 'CURRENT_TIMESTAMP', false, 'DEFAULT_GENERATED'), false)['default']);

        $described = Schema::describe($column('bigint(20) unsigned', null, false, 'auto_increment'), true);
        self::assertSame(['BIGINT', '', true, true], [$described['type'], $described['length'], $described['unsigned'], $described['autoIncrement']]);
        self::assertSame(['a', "b'c"], Schema::describe($column("enum('a','b''c')", null), true)['values']);
        self::assertSame('10,2', Schema::describe($column('decimal(10,2)', null), true)['length']);
    }

    public function testStructureChangesArePreviewedThenRun(): void
    {
        $client = $this->client("CREATE TABLE posts (id INT NOT NULL, title VARCHAR(50) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $schema = new Schema($client, $this->catalog($client));
        $db = $this->database();
        $catalog = $this->catalog($client);

        $preview = $schema->apply($db, ['operation' => 'add-column', 'table' => 'posts', 'column' => ['name' => 'status', 'type' => 'VARCHAR', 'length' => '20', 'default' => ['kind' => 'value', 'value' => 'draft']], 'position' => ['after' => 'id']], true);
        self::assertSame(false, $preview['ran']);
        self::assertSame("ALTER TABLE `{$db}`.`posts` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'draft' AFTER `id`", $preview['sql']);
        self::assertSame(['id', 'title'], array_column($catalog->columns($db, 'posts'), 'name'), 'A preview changes nothing.');

        $schema->apply($db, ['operation' => 'add-column', 'table' => 'posts', 'column' => ['name' => 'status', 'type' => 'VARCHAR', 'length' => '20', 'default' => ['kind' => 'value', 'value' => 'draft']], 'position' => ['after' => 'id']], false);
        self::assertSame(['id', 'status', 'title'], array_column($catalog->columns($db, 'posts'), 'name'));

        $schema->apply($db, ['operation' => 'modify-column', 'table' => 'posts', 'name' => 'title', 'column' => ['name' => 'headline', 'type' => 'TEXT', 'nullable' => true]], false);
        $headline = array_column($catalog->columns($db, 'posts'), null, 'name')['headline'];
        self::assertSame(['text', true], [$headline['dataType'], $headline['nullable']]);

        $schema->apply($db, ['operation' => 'add-index', 'table' => 'posts', 'index' => ['kind' => 'primary', 'columns' => ['id']]], false);
        $schema->apply($db, ['operation' => 'add-index', 'table' => 'posts', 'index' => ['kind' => 'index', 'name' => 'by_headline', 'columns' => [['name' => 'headline', 'length' => '20']]]], false);
        self::assertSame(['PRIMARY', 'by_headline'], array_column($catalog->indexes($db, 'posts'), 'name'));

        $schema->apply($db, ['operation' => 'drop-index', 'table' => 'posts', 'name' => 'PRIMARY'], false);
        $schema->apply($db, ['operation' => 'drop-column', 'table' => 'posts', 'name' => 'status'], false);
        self::assertSame(['by_headline'], array_column($catalog->indexes($db, 'posts'), 'name'));
        self::assertSame(['id', 'headline'], array_column($catalog->columns($db, 'posts'), 'name'));

        $schema->apply($db, ['operation' => 'options', 'table' => 'posts', 'comment' => 'Blog  posts', 'collation' => 'utf8mb4_bin', 'convert' => true], false);
        $table = $catalog->table($db, 'posts');
        self::assertSame(['Blog  posts', 'utf8mb4_bin'], [$table['comment'], $table['collation']]);
        self::assertSame('utf8mb4_bin', array_column($catalog->columns($db, 'posts'), null, 'name')['headline']['collation'], 'Converting changes the columns too.');

        self::assertThrows(UserError::class, fn () => $schema->apply($db, ['operation' => 'drop-column', 'table' => 'posts', 'name' => 'nope'], true), 'does not exist');
        self::assertThrows(UserError::class, fn () => $schema->apply($db, ['operation' => 'options', 'table' => 'posts', 'engine' => 'BLACKHOLE'], true), 'InnoDB');
    }

    public function testTablesAreCreated(): void
    {
        $client = $this->client();
        $schema = new Schema($client, $this->catalog($client));
        $db = $this->database();

        $result = $schema->apply($db, [
            'operation' => 'create-table',
            'name' => 'notes',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'columns' => [
                ['name' => 'id', 'type' => 'INT', 'unsigned' => true, 'autoIncrement' => true, 'primary' => true],
                ['name' => 'body', 'type' => 'TEXT', 'nullable' => true],
                ['name' => 'created', 'type' => 'DATETIME', 'default' => ['kind' => 'current_timestamp']],
            ],
        ], false);

        self::assertTrue(str_starts_with($result['sql'], "CREATE TABLE `{$db}`.`notes` (\n  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,"));
        self::assertSame(['id'], \DbAdmin\Catalog::rowKey($this->catalog($client)->columns($db, 'notes'), $this->catalog($client)->indexes($db, 'notes')));

        self::assertThrows(UserError::class, fn () => $schema->apply($db, ['operation' => 'create-table', 'name' => 'x', 'columns' => [['name' => 'a', 'type' => 'INT'], ['name' => 'A', 'type' => 'INT']]], true), 'same name');
    }

    public function testObjectsAreListedShownAndDropped(): void
    {
        $client = $this->client(
            'CREATE TABLE t (id INT PRIMARY KEY, n INT)',
            'CREATE VIEW v AS SELECT id FROM t',
            'CREATE FUNCTION double_it(x INT) RETURNS INT DETERMINISTIC RETURN x * 2',
            'CREATE PROCEDURE add_one() BEGIN UPDATE t SET n = n + 1; SELECT COUNT(*) FROM t; END',
            'CREATE TRIGGER t_n BEFORE INSERT ON t FOR EACH ROW SET NEW.n = COALESCE(NEW.n, 0)',
            'CREATE EVENT nightly ON SCHEDULE EVERY 1 DAY DISABLE DO DELETE FROM t WHERE n > 100',
        );
        $objects = new Objects($client, $this->catalog($client));
        $db = $this->database();
        $all = $objects->all($db);

        self::assertSame(['v'], array_column($all['views'], 'name'));
        self::assertSame(['double_it'], array_column($all['functions'], 'name'));
        self::assertSame(['add_one'], array_column($all['procedures'], 'name'));
        self::assertSame([['name' => 't_n', 'table' => 't', 'timing' => 'BEFORE', 'event' => 'INSERT']], $all['triggers']);
        self::assertSame('every 1 day', $all['events'][0]['schedule']);

        self::assertTrue(str_contains((string) $objects->definition($db, 'function', 'double_it'), 'RETURN x * 2'));
        self::assertTrue(str_contains((string) $objects->definition($db, 'procedure', 'add_one'), 'UPDATE t SET n = n + 1'));
        self::assertTrue(str_contains((string) $objects->definition($db, 'event', 'nightly'), 'DELETE FROM t'));
        self::assertTrue(str_contains((string) $objects->definition($db, 'trigger', 't_n'), 'COALESCE'));

        $dump = $objects->dump($db);
        self::assertTrue(str_contains($dump, "DELIMITER ;;\nCREATE") && ! str_contains($dump, 'DEFINER='));

        self::assertThrows(UserError::class, fn () => $objects->drop($db, 'procedure', 'double_it'), 'does not exist');
        self::assertThrows(UserError::class, fn () => $objects->drop($db, 'table', 't'), 'Unknown kind');

        $objects->drop($db, 'procedure', 'add_one');
        $objects->drop($db, 'event', 'nightly');
        $all = $objects->all($db);
        self::assertSame([[], []], [$all['procedures'], $all['events']]);
    }

    public function testADatabaseIsSearchedTableByTable(): void
    {
        $client = $this->client(
            'CREATE TABLE posts (id INT PRIMARY KEY, body TEXT)',
            'CREATE TABLE options (id INT PRIMARY KEY, value VARCHAR(100))',
            'CREATE TABLE numbers (id INT PRIMARY KEY, n INT)',
        );
        $client->query("INSERT INTO posts VALUES (1, 'see https://old.example.com'), (2, 'nothing'), (3, 'https://old.example.com again')");
        $client->query("INSERT INTO options VALUES (1, 'https://old.example.com')");
        $client->query('INSERT INTO numbers VALUES (1, 5)');

        $catalog = $this->catalog($client);
        $search = new Search($client, $catalog, new Rows($client, $catalog, 1024, 1048576, 100000));

        self::assertSame(
            ['results' => [['table' => 'options', 'matches' => 1], ['table' => 'posts', 'matches' => 2]], 'skipped' => [], 'searched' => 3],
            $search->run($this->database(), 'old.example.com', [], 10.0),
        );
        self::assertSame([['table' => 'numbers', 'matches' => 1]], $search->run($this->database(), '5', ['numbers'], 10.0)['results']);
        self::assertSame(['numbers', 'options', 'posts'], $search->run($this->database(), 'x', [], 0.0)['skipped']);
    }

    public function testProcessesAreTheUsersOwn(): void
    {
        $client = $this->client();
        $other = $this->freshClient();
        $processes = new Processes($client, $this->target()['user']);

        $ids = array_column($processes->all(), 'id');
        $otherId = (int) $other->value('SELECT CONNECTION_ID()');
        $ownId = (int) $client->value('SELECT CONNECTION_ID()');

        self::assertTrue(in_array($otherId, $ids, true), 'The user\'s other connection is listed.');
        self::assertTrue(! in_array($ownId, $ids, true), 'The listing connection is not.');
        self::assertSame([], (new Processes($client, 'someone_else'))->all());

        $processes->kill($otherId, true);
        self::assertTrue(! in_array($otherId, array_column($processes->all(), 'id'), true));
        self::assertThrows(UserError::class, fn () => $processes->kill(1, true), 'finished');
    }

    private function freshClient(): \DbAdmin\Client
    {
        $target = $this->target();

        return (new \DbAdmin\Connection($this->config()))->open(['user' => $target['user'], 'password' => $target['password']]);
    }
}
