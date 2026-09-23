<?php

declare(strict_types=1);

namespace DbSimply\Tests;

use DbSimply\Sql\Analyzer;
use DbSimply\Sql\Splitter;
use DbSimply\Sql\Statement;

/**
 * Splitting SQL into statements, and telling what each one does.
 */
final class SqlTest extends TestCase
{
    public function testDelimitersInsideLiteralsIdentifiersAndCommentsDoNotSplit(): void
    {
        $sql = <<<'SQL'
            SELECT 'a;b', "c;d", `e;f`; -- trailing; comment
            # hash; comment
            /* block; comment */ SELECT 'it''s', 'back\'slash;', "dq""x;";
            SELECT 3
            SQL;

        self::assertSame(
            ["SELECT 'a;b', \"c;d\", `e;f`", "-- trailing; comment\n# hash; comment\n/* block; comment */ SELECT 'it''s', 'back\\'slash;', \"dq\"\"x;\"", 'SELECT 3'],
            self::sql(Splitter::split($sql)),
        );
    }

    public function testDoubleDashNeedsWhitespaceToStartAComment(): void
    {
        self::assertSame(['SELECT 5--1', 'SELECT 2'], self::sql(Splitter::split("SELECT 5--1;\nSELECT 2;")));
    }

    public function testEmptyAndCommentOnlyStatementsAreDropped(): void
    {
        self::assertSame(['SELECT 1'], self::sql(Splitter::split(";;\n-- nothing\n;\nSELECT 1;\n/* end */")));
    }

    public function testExecutableCommentsAreStatements(): void
    {
        self::assertSame(
            ['/*!40101 SET NAMES utf8mb4 */', '/*M!100100 SET x = 1 */'],
            self::sql(Splitter::split("/*!40101 SET NAMES utf8mb4 */;\n/*M!100100 SET x = 1 */;")),
        );
    }

    public function testDelimiterCommandsChangeTheDelimiter(): void
    {
        $sql = <<<'SQL'
            DELIMITER ;;
            CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = 2; END ;;
            DELIMITER ;
            SELECT 1;
            SQL;

        $statements = Splitter::split($sql);

        self::assertSame(
            ['CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = 2; END', 'SELECT 1'],
            self::sql($statements),
        );
        self::assertSame([2, 4], array_map(static fn (Statement $statement): int => $statement->line, $statements));
    }

    public function testStatementsRecordTheirLines(): void
    {
        $statements = Splitter::split("SELECT 1;\n\n  SELECT\n'multi\nline';\n\nSELECT 3");

        self::assertSame([1, 3, 7], array_map(static fn (Statement $statement): int => $statement->line, $statements));
    }

    public function testFeedingOneByteAtATimeGivesTheSameStatements(): void
    {
        $sql = "DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT ';'; END\$\$\ndelimiter ;\n"
            ."INSERT INTO t VALUES ('a\\'b', \"c\"\"d\", `x``y`); -- c;\n/* long; */ SELECT 1--1\n;SELECT '\n';";

        $whole = Splitter::split($sql);

        $splitter = new Splitter;
        $pieces = [];

        foreach (str_split($sql) as $byte) {
            array_push($pieces, ...$splitter->feed($byte));
        }

        array_push($pieces, ...$splitter->finish());

        self::assertSame(self::sql($whole), self::sql($pieces));
        self::assertSame(
            array_map(static fn (Statement $s): array => [$s->line, $s->endOffset, $s->endLine, $s->delimiter], $whole),
            array_map(static fn (Statement $s): array => [$s->line, $s->endOffset, $s->endLine, $s->delimiter], $pieces),
        );
    }

    public function testSplittingResumesFromARecordedPosition(): void
    {
        $sql = "DELIMITER //\nSELECT 1//\nSELECT 2//\nSELECT 3//";
        $first = Splitter::split($sql)[0];

        $resumed = new Splitter($first->delimiter, $first->endOffset, $first->endLine);
        $rest = [...$resumed->feed(substr($sql, $first->endOffset)), ...$resumed->finish()];

        self::assertSame(['SELECT 2', 'SELECT 3'], self::sql($rest));
        self::assertSame(strlen($sql), $rest[1]->endOffset);
        self::assertSame(4, $rest[1]->line);
    }

    public function testReadOnlyStatementsAreRecognised(): void
    {
        foreach ([
            'SELECT * FROM t',
            "  -- note\n select 1",
            'SHOW TABLES',
            'DESCRIBE t',
            'EXPLAIN SELECT 1',
            'EXPLAIN UPDATE t SET a = 1',
            'WITH x AS (SELECT 1) SELECT * FROM x',
            'ANALYZE SELECT 1',
            "SELECT 'INSERT INTO t' AS s",
        ] as $sql) {
            self::assertTrue(Analyzer::analyze($sql)['readOnly'], "Expected read-only: {$sql}");
        }

        foreach ([
            'INSERT INTO t VALUES (1)',
            'SELECT * FROM t INTO OUTFILE "/tmp/x"',
            'WITH x AS (SELECT 1) DELETE FROM t',
            'ANALYZE UPDATE t SET a = 1',
            'EXPLAIN ANALYZE DELETE FROM t',
            'SET SESSION TRANSACTION READ WRITE',
            '/*!40101 DROP TABLE t */',
            'CALL p()',
            'DO SLEEP(1)',
        ] as $sql) {
            self::assertTrue(! Analyzer::analyze($sql)['readOnly'], "Expected a write: {$sql}");
        }
    }

    public function testDestructiveStatementsAreRecognised(): void
    {
        self::assertSame('drops a table', Analyzer::analyze('DROP TABLE wp_posts')['destructive']);
        self::assertSame('drops a table', Analyzer::analyze('drop temporary table x')['destructive']);
        self::assertSame('drops a database', Analyzer::analyze('DROP DATABASE shop')['destructive']);
        self::assertSame('empties a table', Analyzer::analyze('TRUNCATE wp_options')['destructive']);
        self::assertSame('deletes every row of a table', Analyzer::analyze('DELETE FROM t')['destructive']);
        self::assertSame('deletes every row of a table', Analyzer::analyze('DELETE FROM t WHERE_x = 1')['destructive']);
        self::assertSame('deletes every row of a table', Analyzer::analyze('DELETE FROM t ORDER BY (SELECT 1 WHERE 1)')['destructive']);
        self::assertSame('changes every row of a table', Analyzer::analyze("UPDATE t SET a = 'WHERE'")['destructive']);
        self::assertSame('drops part of a table', Analyzer::analyze('ALTER TABLE t DROP COLUMN a')['destructive']);

        self::assertSame(null, Analyzer::analyze('DELETE FROM t WHERE id = 1')['destructive']);
        self::assertSame(null, Analyzer::analyze('UPDATE t SET a = 1 WHERE id IN (SELECT 1)')['destructive']);
        self::assertSame(null, Analyzer::analyze('ALTER TABLE t ADD COLUMN dropped INT')['destructive']);
        self::assertSame(null, Analyzer::analyze("SELECT 'DROP TABLE t'")['destructive']);
    }

    /**
     * @param  list<Statement>  $statements
     * @return list<string>
     */
    private static function sql(array $statements): array
    {
        return array_map(static fn (Statement $statement): string => $statement->sql, $statements);
    }
}
