<?php

declare(strict_types=1);

namespace DbAdmin\Tests;

use DbAdmin\Client;
use DbAdmin\Export;
use DbAdmin\Import;
use DbAdmin\UserError;

/**
 * Export and import, and a dump surviving the round trip between them.
 */
final class TransferTest extends TestCase
{
    public function testCsvQuotesOnlyWhatNeedsIt(): void
    {
        self::assertSame("a,\"b,c\",\"say \"\"hi\"\"\",,\"\",\"x\ny\"\r\n", Export::csvLine(['a', 'b,c', 'say "hi"', null, '', "x\ny"]));
    }

    public function testDefinersAreLeftOut(): void
    {
        self::assertSame(
            'CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1',
            Export::withoutDefiner('CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v` AS select 1'),
        );
        self::assertSame(
            'CREATE TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET NEW.a = 1',
            Export::withoutDefiner('CREATE DEFINER=`acct``1`@`local host` TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET NEW.a = 1'),
        );
    }

    public function testViewsAreOrderedAfterTheViewsTheyUse(): void
    {
        $statements = ['a' => 'CREATE VIEW `a` AS select * from `b`', 'b' => 'CREATE VIEW `b` AS select * from `c`', 'c' => 'CREATE VIEW `c` AS select 1'];

        self::assertSame(['c', 'b', 'a'], array_keys(Export::viewOrder(
            [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
            static fn (string $name): string => $statements[$name],
        )));
    }

    public function testImportRefusesWhatItShouldNot(): void
    {
        $dir = self::tempDir();
        $import = new Import($dir, 'owner', 1000);

        self::assertThrows(UserError::class, fn () => $import->start('dump.sql', 1001, 'db'), 'larger');
        self::assertThrows(UserError::class, fn () => $import->start('dump.exe', 10, 'db'), '.sql');
        self::assertThrows(UserError::class, fn () => $import->start('dump.sql', 0, 'db'), 'empty');

        $state = $import->start('dump.sql', 4, 'db');

        self::assertThrows(UserError::class, fn () => $import->append($state['id'], 2, 'ab'), 'out of step');
        self::assertThrows(UserError::class, fn () => $import->append($state['id'], 0, 'abcde'), 'larger than announced');
        self::assertThrows(UserError::class, fn () => $import->append('../../etc/passwd', 0, 'ab'), 'Unknown');

        // Another session cannot see or drive it.
        self::assertThrows(UserError::class, fn () => (new Import($dir, 'someone else', 1000))->status($state['id']), 'Unknown');
    }

    public function testADumpSurvivesTheRoundTrip(): void
    {
        $client = $this->client(...self::schema());
        $db = $this->database();

        $client->query("INSERT INTO b_parent (id, label) VALUES (1, 'one'), (2, 'two')");
        $client->query("INSERT INTO a_child (id, parent_id) VALUES (1, 1), (2, 2), (3, 1)");
        $client->query(sprintf(
            "INSERT INTO kinds (id, txt, bin, flag, js, amount, stamp, created, nothing) VALUES
             (1, %s, 0x00FF10, b'1', '{\"a\": [1, 2]}', 12.50, '2026-01-02 03:04:05', '2026-01-02 03:04:05', NULL),
             (2, '', '', b'0', NULL, -0.01, '2030-12-31 23:59:59', '1999-01-01 00:00:00', NULL)",
            $client->quote("it's \"quoted\"; with -- dashes /* and */ \\ backslash, émoji 🎉, and a\nnewline"),
        ));
        $client->query('INSERT INTO kinds (id, txt) VALUES '.implode(', ', array_map(static fn (int $i): string => "({$i}, REPEAT('x', 5000))", range(3, 400))));

        $before = self::snapshot($client);

        $dump = '';
        (new Export($client, $this->catalog($client), static function (string $bytes) use (&$dump): void {
            $dump .= $bytes;
        }))->sql($db, [], true, true);

        self::assertTrue(! str_contains($dump, 'DEFINER='), 'Definers are left out of the dump.');
        self::assertTrue(substr_count($dump, 'INSERT INTO `kinds`') > 1, 'Long tables are split into several INSERTs.');

        // Start from nothing, then import the dump gzipped, a statement at a
        // time: every slice is a new connection, as in production.
        $client = $this->client();
        $dir = self::tempDir();
        $import = new Import($dir, 'owner', 100 * 1024 * 1024);
        $gzip = (string) gzencode($dump);

        $state = $import->start('backup.sql.gz', strlen($gzip), $db);

        foreach (str_split($gzip, 7000) as $chunk) {
            $state = $import->append($state['id'], $state['received'], $chunk);
        }

        self::assertSame('ready', $state['state']);

        $runs = 0;

        while ($state['state'] !== 'done' && $runs++ < 10000) {
            $connection = $this->freshClient();
            $state = $import->run($state['id'], $connection, 0.0);
            $connection->close();

            self::assertTrue($state['state'] !== 'failed', 'The import failed: '.json_encode($state['error']));
        }

        self::assertSame('done', $state['state']);
        self::assertTrue($runs > 10, 'The import ran in many slices.');
        self::assertSame($before, self::snapshot($client));
        self::assertSame([], glob($dir.'/*'), 'A finished import leaves nothing behind.');
    }

    public function testAFailedStatementCanBeSkipped(): void
    {
        $client = $this->client('CREATE TABLE t (id INT PRIMARY KEY)');
        $import = new Import(self::tempDir(), 'owner', 1024 * 1024);
        $sql = "INSERT INTO t VALUES (1);\nINSERT INTO t VALUES (1);\nINSERT INTO t VALUES (2);\n";

        $state = $import->start('dump.sql', strlen($sql), $this->database());
        $state = $import->append($state['id'], 0, $sql);
        $state = $import->run($state['id'], $client, 30.0);

        self::assertSame('failed', $state['state']);
        self::assertSame(2, $state['error']['line']);
        self::assertTrue(str_contains($state['error']['message'], 'Duplicate'));
        self::assertSame(true, $state['canSkip']);

        $import->skip($state['id']);
        $state = $import->run($state['id'], $client, 30.0);

        self::assertSame('done', $state['state']);
        self::assertSame('1,2', $client->value('SELECT GROUP_CONCAT(id ORDER BY id) FROM t'));
    }

    public function testCsvExportOfATableAndAQuery(): void
    {
        $client = $this->client('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(20) NULL)');
        $client->query("INSERT INTO t VALUES (1, 'a,b'), (2, NULL), (3, '')");

        $csv = '';
        $export = new Export($client, $this->catalog($client), static function (string $bytes) use (&$csv): void {
            $csv .= $bytes;
        });

        $export->csvTable($this->database(), 't', '`id` < 3');
        self::assertSame("id,name\r\n1,\"a,b\"\r\n2,\r\n", $csv);

        $csv = '';
        $export->csvQuery($this->database(), 'SELECT COUNT(*) AS n FROM t');
        self::assertSame("n\r\n3\r\n", $csv);

        self::assertThrows(UserError::class, fn () => $export->csvQuery($this->database(), 'DELETE FROM t'), 'reads');
        self::assertThrows(UserError::class, fn () => $export->csvQuery($this->database(), 'SELECT 1; SELECT 2'), 'one statement');
    }

    /**
     * @return list<string>
     */
    private static function schema(): array
    {
        return [
            // The child sorts first, so its rows are dumped before the rows
            // they point to: only FOREIGN_KEY_CHECKS = 0, replayed into every
            // slice of the import, lets them in.
            'CREATE TABLE b_parent (id INT PRIMARY KEY, label VARCHAR(20) NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE a_child (id INT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES b_parent (id)) ENGINE=InnoDB',
            "CREATE TABLE kinds (
                id INT PRIMARY KEY,
                txt TEXT NULL,
                bin VARBINARY(20) NULL,
                flag BIT(1) NULL,
                js JSON NULL,
                amount DECIMAL(10, 2) NULL,
                stamp TIMESTAMP NULL,
                created DATETIME NULL,
                nothing VARCHAR(5) NULL,
                txt_length INT AS (CHAR_LENGTH(txt)) STORED
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'CREATE TRIGGER kinds_amount BEFORE INSERT ON kinds FOR EACH ROW BEGIN IF NEW.amount IS NULL THEN SET NEW.amount = 0; END IF; END',
            'CREATE VIEW parent_labels AS SELECT label FROM b_parent',
            'CREATE VIEW a_labels AS SELECT label FROM parent_labels',
            "CREATE FUNCTION label_of(x INT) RETURNS VARCHAR(20) READS SQL DATA BEGIN DECLARE l VARCHAR(20); SELECT label INTO l FROM b_parent WHERE id = x; RETURN CONCAT(l, ';'); END",
            'CREATE PROCEDURE bump(IN by_amount DECIMAL(10, 2)) BEGIN UPDATE kinds SET amount = amount + by_amount WHERE id = 1; END',
            'CREATE EVENT tidy ON SCHEDULE EVERY 1 HOUR DISABLE DO DELETE FROM kinds WHERE id > 1000',
        ];
    }

    /**
     * Every table's rows, and every object's name, in a comparable form.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Client $client): array
    {
        $client->query("SET time_zone = '+00:00'");
        $snapshot = [];

        foreach ($client->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $table) {
            $name = '`'.$table['name'].'`';
            $snapshot[$table['type'].' '.$table['name']] = $client->select("SELECT * FROM {$name} ORDER BY 1");
        }

        $snapshot['triggers'] = $client->select('SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
        $snapshot['routines'] = $client->select('SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME');
        $snapshot['events'] = $client->select('SELECT EVENT_NAME, EVENT_DEFINITION, STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()');
        $snapshot['label'] = $client->value('SELECT label_of(1)');

        return $snapshot;
    }

    private function freshClient(): Client
    {
        $target = $this->target();
        return (new \DbAdmin\Connection($this->config()))->open(['user' => $target['user'], 'password' => $target['password']]);
    }
}
