<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use DbSimply\Replace;
use DbSimply\Serialized;
use DbSimply\UserError;

/**
 * Search and replace, and keeping serialized PHP intact through it.
 */
final class ReplaceTest extends TestCase
{
    private const string OLD = 'https://old.example.com';

    private const string NEW = 'https://www.new-example.org';

    public function testSerializedStringsKeepTheirLengthsRight(): void
    {
        $value = serialize(['home' => self::OLD, 'n' => 5, 'f' => 1.5, 'b' => false, 'x' => null, 'deep' => ['url' => self::OLD.'/a', 'ü' => 'é '.self::OLD]]);
        $replaced = Serialized::replace($value, self::OLD, self::NEW);

        self::assertSame(
            ['home' => self::NEW, 'n' => 5, 'f' => 1.5, 'b' => false, 'x' => null, 'deep' => ['url' => self::NEW.'/a', 'ü' => 'é '.self::NEW]],
            unserialize($replaced),
        );
    }

    public function testObjectsOfUnknownClassesAndNestedSerializedDataSurvive(): void
    {
        // A class this code has never seen, with private and protected properties.
        $object = 'O:9:"WP_Widget":3:{s:3:"url";s:23:"'.self::OLD.'";s:12:"'."\0".'WP_Widget'."\0".'p";s:23:"'.self::OLD.'";s:4:"'."\0".'*'."\0".'q";i:1;}';
        $replaced = Serialized::replace($object, self::OLD, self::NEW);

        self::assertTrue(Serialized::isSerialized($replaced));
        self::assertSame(2, substr_count($replaced, self::NEW));
        self::assertTrue(str_contains($replaced, 's:27:"'.self::NEW.'"'));

        $double = serialize(serialize(['url' => self::OLD]));
        self::assertSame(['url' => self::NEW], unserialize(unserialize(Serialized::replace($double, self::OLD, self::NEW))));
    }

    public function testKeysAndCustomSerializedPayloadsAreLeftAlone(): void
    {
        $value = 'a:2:{s:23:"'.self::OLD.'";s:23:"'.self::OLD.'";i:0;C:11:"ArrayObject":23:{'.self::OLD.'}}';
        $replaced = Serialized::replace($value, self::OLD, self::NEW);

        self::assertSame('a:2:{s:23:"'.self::OLD.'";s:27:"'.self::NEW.'";i:0;C:11:"ArrayObject":23:{'.self::OLD.'}}', $replaced);
    }

    public function testPlainAndBrokenValuesAreReplacedAsText(): void
    {
        self::assertSame('see '.self::NEW, Serialized::replace('see '.self::OLD, self::OLD, self::NEW));
        self::assertSame('s:99:"'.self::NEW.'";', Serialized::replace('s:99:"'.self::OLD.'";', self::OLD, self::NEW));
        self::assertSame(false, Serialized::isSerialized('a:1:{i:0;s:5:"abc";}'));
        self::assertSame(true, Serialized::isSerialized('b:0;'));
    }

    public function testReplacingAcrossADatabaseInSlices(): void
    {
        $client = $this->client(
            'CREATE TABLE options (id INT PRIMARY KEY, name VARCHAR(64) NOT NULL, value LONGTEXT NOT NULL) DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE posts (id INT PRIMARY KEY, body TEXT, guid VARCHAR(255)) DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE loose (body TEXT)',
            'CREATE TABLE slugs (slug VARCHAR(191) PRIMARY KEY, target TEXT)',
        );

        $client->query(sprintf(
            "INSERT INTO options VALUES (1, 'siteurl', %s), (2, 'widgets', %s), (3, 'other', 'untouched'), (4, 'caps', %s)",
            $client->quote(self::OLD),
            $client->quote(serialize(['title' => 'Links', 'links' => [self::OLD.'/a', self::OLD.'/b']])),
            $client->quote(strtoupper(self::OLD)),
        ));
        $client->query('INSERT INTO posts VALUES '.implode(', ', array_map(
            fn (int $i): string => sprintf('(%d, %s, %s)', $i, $client->quote("Post {$i} links to ".self::OLD."/{$i}"), $client->quote(self::OLD."/?p={$i}")),
            range(1, 250),
        )));
        $client->query(sprintf('INSERT INTO loose VALUES (%s)', $client->quote(self::OLD)));
        $client->query(sprintf('INSERT INTO slugs VALUES (%s, %s)', $client->quote(self::OLD), $client->quote(self::OLD)));

        $replace = new Replace($client, $this->catalog($client));
        $db = $this->database();

        $preview = $replace->preview($db, self::OLD, self::NEW, [], 10.0);
        $byTable = array_column($preview['tables'], null, 'table');

        self::assertSame(['loose', 'options', 'posts', 'slugs'], array_keys($byTable));
        self::assertSame(2, $byTable['options']['rows'], 'Matching is case-sensitive.');
        self::assertSame(250, $byTable['posts']['rows']);
        self::assertSame(false, $byTable['loose']['editable']);
        self::assertTrue(str_contains($byTable['options']['samples'][0]['after'], self::NEW));
        self::assertSame('0', $client->value(sprintf('SELECT COUNT(*) FROM posts WHERE body LIKE %s', $client->quoteLike(self::NEW, '%', '%'))), 'A preview changes nothing.');

        // No time at all: every call does one batch and hands back its place.
        $cursor = null;
        $changed = [];
        $calls = 0;

        do {
            $result = $replace->run($db, self::OLD, self::NEW, [], $cursor, 0.0);
            $cursor = $result['cursor'];

            foreach ($result['changed'] as $table => $count) {
                $changed[$table] = ($changed[$table] ?? 0) + $count;
            }
        } while (! $result['done'] && ++$calls < 100);

        self::assertSame(['options' => 2, 'posts' => 250, 'slugs' => 1], $changed);
        self::assertTrue($calls > 2, 'The work was split over several calls.');

        self::assertSame(['title' => 'Links', 'links' => [self::NEW.'/a', self::NEW.'/b']], unserialize((string) $client->value('SELECT value FROM options WHERE id = 2')));
        self::assertSame(strtoupper(self::OLD), $client->value('SELECT value FROM options WHERE id = 4'));
        self::assertSame('0', $client->value(sprintf('SELECT COUNT(*) FROM posts WHERE body LIKE %1$s OR guid LIKE %1$s', $client->quoteLike(self::OLD, '%', '%'))));
        self::assertSame(self::OLD, $client->value('SELECT body FROM loose'), 'A table without a row key is left alone.');
        self::assertSame([self::OLD, self::NEW], $client->selectRows('SELECT slug, target FROM slugs')[0], 'The row key itself is never rewritten.');

        self::assertThrows(UserError::class, fn () => $replace->run($db, 'x', 'x', [], null, 1.0), 'same');
        self::assertThrows(UserError::class, fn () => $replace->run($db, 'x', 'y', [], ['table' => 'nope'], 1.0), 'lost its place');
    }
}
