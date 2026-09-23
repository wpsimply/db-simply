<?php

declare(strict_types=1);

namespace DbAdmin\Tests;

use DbAdmin\Editor;
use DbAdmin\TableOperations;
use DbAdmin\UserError;

/**
 * Editing rows, and whole-table operations.
 */
final class EditingTest extends TestCase
{
    private const string ITEMS = "CREATE TABLE items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        note TEXT NULL,
        status ENUM('new', 'it''s done') NOT NULL DEFAULT 'new',
        flag BIT(1) NOT NULL DEFAULT b'0',
        payload VARBINARY(16) NULL,
        created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        name_length INT AS (CHAR_LENGTH(name)) VIRTUAL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    public function testFieldsDescribeWhatAFormNeeds(): void
    {
        $client = $this->client(self::ITEMS);
        $fields = array_column((new Editor($client, $this->catalog($client), 1024))->fields($this->database(), 'items'), null, 'name');

        self::assertSame(true, $fields['id']['autoIncrement']);
        self::assertSame(['new', "it's done"], $fields['status']['options']);
        self::assertSame(true, $fields['payload']['binary']);
        self::assertSame(true, $fields['note']['long']);
        self::assertSame(true, $fields['name_length']['generated']);
        self::assertSame(false, $fields['created']['generated'], 'A DEFAULT CURRENT_TIMESTAMP column is not generated.');
    }

    public function testRowsAreInsertedReadUpdatedAndDeleted(): void
    {
        $client = $this->client(self::ITEMS);
        $editor = new Editor($client, $this->catalog($client), 1024);
        $db = $this->database();

        $inserted = $editor->insert($db, 'items', ['name' => "O'Brien", 'flag' => '1', 'payload' => ['$b64' => base64_encode("\x00\xff")], 'note' => null]);
        self::assertSame('1', $inserted['insertId']);

        $row = $editor->row($db, 'items', ['id' => '1']);
        self::assertSame(['id'], $row['key']);
        self::assertSame("O'Brien", $row['values']['name']);
        self::assertSame(null, $row['values']['note']);
        self::assertSame('new', $row['values']['status'], 'A column left out gets its default.');
        self::assertSame('1', $row['values']['flag']);
        self::assertSame(['$b64' => base64_encode("\x00\xff")], $row['values']['payload']);
        self::assertSame('7', $row['values']['name_length']);

        self::assertSame(['changed' => 1], $editor->update($db, 'items', ['id' => '1'], ['status' => "it's done", 'note' => 'hello']));
        self::assertSame("it's done", $client->value('SELECT status FROM items WHERE id = 1'));

        self::assertThrows(UserError::class, fn () => $editor->update($db, 'items', ['id' => '1'], ['name_length' => '3']), 'generated');
        self::assertThrows(UserError::class, fn () => $editor->update($db, 'items', ['id' => '1'], ['nope' => '3']), 'no column');
        self::assertThrows(UserError::class, fn () => $editor->update($db, 'items', ['id' => '99'], ['name' => 'x']), 'no longer exists');
        self::assertThrows(UserError::class, fn () => $editor->update($db, 'items', [], ['name' => 'x']), 'incomplete');

        $editor->insert($db, 'items', ['name' => 'b']);
        $editor->insert($db, 'items', ['name' => 'c']);

        self::assertSame(['deleted' => 2], $editor->delete($db, 'items', [['id' => '1'], ['id' => '3']]));
        self::assertSame('2', $client->value('SELECT GROUP_CONCAT(id) FROM items'));
    }

    public function testTruncatedValuesCannotBeWrittenBack(): void
    {
        $client = $this->client(self::ITEMS);
        $editor = new Editor($client, $this->catalog($client), 10);
        $editor->insert($this->database(), 'items', ['name' => 'x', 'note' => str_repeat('a', 50)]);

        $note = $editor->row($this->database(), 'items', ['id' => '1'])['values']['note'];

        self::assertSame(50, $note['len']);
        self::assertThrows(UserError::class, fn () => $editor->update($this->database(), 'items', ['id' => '1'], ['note' => $note]));
    }

    public function testTablesWithoutARowKeyAreNotEditedRowByRow(): void
    {
        $client = $this->client('CREATE TABLE loose (a INT, b INT)');
        $editor = new Editor($client, $this->catalog($client), 1024);

        $editor->insert($this->database(), 'loose', ['a' => '1', 'b' => '1']);

        self::assertThrows(UserError::class, fn () => $editor->delete($this->database(), 'loose', [['a' => '1']]), 'no primary or unique key');
    }

    public function testTableOperations(): void
    {
        $client = $this->client(self::ITEMS, 'CREATE TABLE logs (id INT PRIMARY KEY) ENGINE=InnoDB', 'CREATE VIEW recent AS SELECT id FROM logs');
        $operations = new TableOperations($client, $this->catalog($client));
        $db = $this->database();

        $client->query("INSERT INTO items (name) VALUES ('a'), ('b')");

        $operations->run($db, ['items'], 'empty');
        self::assertSame('0', $client->value('SELECT COUNT(*) FROM items'));

        $messages = $operations->run($db, ['items', 'logs'], 'optimize')['messages'];
        self::assertTrue(count($messages) >= 2);

        $operations->run($db, ['logs'], 'rename', 'log_archive');
        self::assertSame(['items', 'log_archive', 'recent'], array_column($this->catalog($client)->tables($db), 'name'));

        self::assertThrows(UserError::class, fn () => $operations->run($db, ['recent'], 'truncate'), 'view');
        self::assertThrows(UserError::class, fn () => $operations->run($db, ['items'], 'rename', ''), 'new name');
        self::assertThrows(UserError::class, fn () => $operations->run($db, ['items'], 'grant'), 'Unknown');
        self::assertThrows(UserError::class, fn () => $operations->run($db, ['mysql.user'], 'drop'));

        $operations->run($db, ['recent', 'items'], 'drop');
        self::assertSame(['log_archive'], array_column($this->catalog($client)->tables($db), 'name'));
    }
}
