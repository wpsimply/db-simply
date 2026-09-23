<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * Builds and runs the statements that change a table's structure: columns,
 * indexes, table options, and new tables.
 *
 * Nothing the browser sends becomes SQL as it is. A column's type comes from
 * a fixed list, its length and precision must be numbers, enum values and
 * defaults are quoted as string literals, and the only default expression
 * accepted is CURRENT_TIMESTAMP. Every operation can be previewed: the
 * statement is built and handed back without running, so the user sees
 * exactly what will happen before it does.
 */
final class Schema
{
    public const array TYPES = [
        'numeric' => ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BIT'],
        'text' => ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'JSON', 'ENUM', 'SET'],
        'binary' => ['BINARY', 'VARBINARY', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB'],
        'time' => ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR'],
    ];

    /**
     * Types that take a length or precision in parentheses, and whether it
     * is required.
     */
    private const array LENGTHS = [
        'TINYINT' => false, 'SMALLINT' => false, 'MEDIUMINT' => false, 'INT' => false, 'BIGINT' => false,
        'DECIMAL' => false, 'FLOAT' => false, 'DOUBLE' => false, 'BIT' => false,
        'CHAR' => false, 'VARCHAR' => true, 'BINARY' => false, 'VARBINARY' => true,
        'DATETIME' => false, 'TIMESTAMP' => false, 'TIME' => false,
    ];

    private const array UNSIGNED = ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE'];

    private const array COLLATED = ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET'];

    /**
     * Types that cannot have a literal default on every supported server.
     */
    private const array NO_LITERAL_DEFAULT = ['TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'JSON', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB'];

    public const array ENGINES = ['InnoDB', 'MyISAM', 'Aria'];

    public function __construct(private readonly Client $client, private readonly Catalog $catalog) {}

    /**
     * Build, and unless previewing run, one structure change.
     *
     * @param  array<string, mixed>  $change
     * @return array{sql: string, ran: bool}
     */
    public function apply(string $database, array $change, bool $preview): array
    {
        $sql = $this->statement($database, $change);

        if (! $preview) {
            $this->client->query($sql);
        }

        return ['sql' => $sql, 'ran' => ! $preview];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function statement(string $database, array $change): string
    {
        $operation = $change['operation'] ?? null;

        if ($operation === 'create-table') {
            return $this->createTable($database, $change);
        }

        $table = $this->catalog->table($database, $change['table'] ?? null);

        if ($table['view']) {
            throw new UserError('A view has no structure of its own to change. Edit its query instead.');
        }

        $alter = 'ALTER TABLE '.Identifier::qualified($database, $table['name']).' ';
        $columns = array_column($this->catalog->columns($database, $table['name']), null, 'name');

        return match ($operation) {
            'add-column' => $alter.'ADD COLUMN '.self::columnDefinition($change['column'] ?? null).$this->position($change['position'] ?? null, $columns),
            'modify-column' => $alter.sprintf(
                'CHANGE COLUMN %s %s%s',
                Identifier::quote($this->existingColumn($change['name'] ?? null, $columns)['name']),
                self::columnDefinition($change['column'] ?? null),
                $this->position($change['position'] ?? null, $columns),
            ),
            'drop-column' => $alter.'DROP COLUMN '.Identifier::quote($this->existingColumn($change['name'] ?? null, $columns)['name']),
            'add-index' => $alter.'ADD '.$this->indexDefinition($change['index'] ?? null, $columns),
            'drop-index' => $alter.$this->dropIndex($database, $table['name'], $change['name'] ?? null),
            'options' => $alter.$this->tableOptions($change),
            default => throw new UserError('Unknown structure change.'),
        };
    }

    /**
     * A column definition: name, type, and everything after it.
     */
    public static function columnDefinition(mixed $column): string
    {
        if (! is_array($column)) {
            throw new UserError('Describe the column.');
        }

        $name = trim((string) ($column['name'] ?? ''));

        if (! Identifier::isValid($name)) {
            throw new UserError('Give the column a name of 1 to 64 characters.');
        }

        $type = strtoupper(trim((string) ($column['type'] ?? '')));

        if (! in_array($type, array_merge(...array_values(self::TYPES)), true)) {
            throw new UserError(sprintf('Choose a type for "%s".', $name));
        }

        $sql = Identifier::quote($name).' '.$type;
        $length = trim((string) ($column['length'] ?? ''));

        if ($type === 'ENUM' || $type === 'SET') {
            $values = is_array($column['values'] ?? null) ? array_values(array_filter($column['values'], is_string(...))) : [];

            if ($values === []) {
                throw new UserError(sprintf('List the values "%s" allows.', $name));
            }

            $sql .= '('.implode(',', array_map(self::literal(...), $values)).')';
        } elseif (array_key_exists($type, self::LENGTHS)) {
            if ($length === '' && self::LENGTHS[$type]) {
                throw new UserError(sprintf('%s needs a length for "%s".', $type, $name));
            }

            if ($length !== '') {
                if (preg_match('/^\d{1,5}(\s*,\s*\d{1,2})?$/', $length) !== 1) {
                    throw new UserError(sprintf('The length of "%s" must be a number, or two numbers for precision and scale.', $name));
                }

                $sql .= '('.preg_replace('/\s+/', '', $length).')';
            }
        }

        if (($column['unsigned'] ?? false) === true && in_array($type, self::UNSIGNED, true)) {
            $sql .= ' UNSIGNED';
        }

        $collation = trim((string) ($column['collation'] ?? ''));

        if ($collation !== '' && in_array($type, self::COLLATED, true)) {
            $sql .= ' COLLATE '.self::collation($collation);
        }

        $nullable = ($column['nullable'] ?? false) === true;
        $sql .= $nullable ? ' NULL' : ' NOT NULL';

        $default = is_array($column['default'] ?? null) ? $column['default'] : ['kind' => 'none'];

        $sql .= match ($default['kind'] ?? 'none') {
            'none' => '',
            'null' => $nullable ? ' DEFAULT NULL' : throw new UserError(sprintf('"%s" cannot default to NULL unless it allows NULL.', $name)),
            'current_timestamp' => in_array($type, ['DATETIME', 'TIMESTAMP'], true)
                ? ' DEFAULT CURRENT_TIMESTAMP'.($length !== '' ? '('.(int) $length.')' : '')
                : throw new UserError(sprintf('Only DATETIME and TIMESTAMP columns can default to the current time, and "%s" is %s.', $name, $type)),
            'value' => in_array($type, self::NO_LITERAL_DEFAULT, true)
                ? throw new UserError(sprintf('%s columns cannot have a default value, so "%s" cannot either.', $type, $name))
                : ' DEFAULT '.self::defaultLiteral($type, (string) ($default['value'] ?? '')),
            default => throw new UserError('Unknown kind of default.'),
        };

        if (($column['onUpdateCurrentTimestamp'] ?? false) === true) {
            if (! in_array($type, ['DATETIME', 'TIMESTAMP'], true)) {
                throw new UserError(sprintf('Only DATETIME and TIMESTAMP columns can update to the current time, and "%s" is %s.', $name, $type));
            }

            $sql .= ' ON UPDATE CURRENT_TIMESTAMP'.($length !== '' ? '('.(int) $length.')' : '');
        }

        if (($column['autoIncrement'] ?? false) === true) {
            if (! in_array($type, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'], true)) {
                throw new UserError(sprintf('Only integer columns can auto-increment, and "%s" is %s.', $name, $type));
            }

            $sql .= ' AUTO_INCREMENT';
        }

        $comment = (string) ($column['comment'] ?? '');

        if ($comment !== '') {
            $sql .= ' COMMENT '.self::literal(mb_substr($comment, 0, 1024));
        }

        return $sql;
    }

    /**
     * Read a column as the server reports it back into the shape
     * {@see self::columnDefinition()} takes, to fill in an edit form.
     *
     * The two servers report defaults differently. MariaDB quotes a literal
     * ('abc'), writes NULL for DEFAULT NULL, and gives no value for no
     * default. MySQL writes a literal as it is, gives no value for both, and
     * marks expressions with DEFAULT_GENERATED.
     *
     * @param  array{name: string, type: string, nullable: bool, default: ?string, extra: string, collation: ?string, comment: string}  $column
     * @return array<string, mixed>
     */
    public static function describe(array $column, bool $mariaDb): array
    {
        preg_match('/^(\w+)(?:\((.*)\))?(.*)$/s', $column['type'], $match);
        $type = strtoupper($match[1] ?? '');
        $inside = $match[2] ?? '';
        $rest = strtolower($match[3] ?? '');
        $extra = strtolower($column['extra']);
        $raw = $column['default'];

        $default = ['kind' => 'none', 'value' => ''];

        if (preg_match('/^current_timestamp(\(\d*\))?$/i', (string) $raw) === 1) {
            $default = ['kind' => 'current_timestamp', 'value' => ''];
        } elseif ($mariaDb) {
            if ($raw === 'NULL') {
                $default = ['kind' => 'null', 'value' => ''];
            } elseif ($raw !== null) {
                $default = ['kind' => 'value', 'value' => str_starts_with($raw, "'") ? str_replace("''", "'", substr($raw, 1, -1)) : $raw];
            }
        } elseif ($raw !== null) {
            $default = ['kind' => 'value', 'value' => $raw];
        } elseif ($column['nullable']) {
            $default = ['kind' => 'null', 'value' => ''];
        }

        $values = [];

        if ($type === 'ENUM' || $type === 'SET') {
            preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $inside, $options);
            $values = array_map(static fn (string $option): string => str_replace("''", "'", stripcslashes($option)), $options[1]);
            $inside = '';
        }

        // Display widths on integers (int(11)) mean nothing and MySQL 8 has
        // dropped them; the form leaves them out.
        if (in_array($type, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'], true)) {
            $inside = '';
        }

        return [
            'name' => $column['name'],
            'type' => $type === 'INTEGER' ? 'INT' : ($type === 'BOOLEAN' || $type === 'BOOL' ? 'TINYINT' : $type),
            'length' => $inside,
            'values' => $values,
            'unsigned' => str_contains($rest, 'unsigned'),
            'nullable' => $column['nullable'],
            'default' => $default,
            'onUpdateCurrentTimestamp' => str_contains($extra, 'on update current_timestamp'),
            'autoIncrement' => str_contains($extra, 'auto_increment'),
            'collation' => $column['collation'] ?? '',
            'comment' => $column['comment'],
            'generated' => str_contains($extra, 'generated') && ! str_contains($extra, 'default_generated'),
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function createTable(string $database, array $change): string
    {
        $name = trim((string) ($change['name'] ?? ''));

        if (! Identifier::isValid($name)) {
            throw new UserError('Give the table a name of 1 to 64 characters.');
        }

        $columns = is_array($change['columns'] ?? null) ? array_values($change['columns']) : [];

        if ($columns === []) {
            throw new UserError('A table needs at least one column.');
        }

        $definitions = array_map(self::columnDefinition(...), $columns);
        $names = array_map(static fn (array $column): string => trim((string) ($column['name'] ?? '')), $columns);

        if (count(array_unique(array_map(strtolower(...), $names))) !== count($names)) {
            throw new UserError('Two columns have the same name.');
        }

        $primary = array_values(array_filter($columns, static fn (array $column): bool => ($column['primary'] ?? false) === true));

        if ($primary !== []) {
            $definitions[] = 'PRIMARY KEY ('.implode(', ', array_map(static fn (array $column): string => Identifier::quote(trim((string) $column['name'])), $primary)).')';
        }

        return sprintf(
            "CREATE TABLE %s (\n  %s\n)%s",
            Identifier::qualified($database, $name),
            implode(",\n  ", $definitions),
            $this->tableOptionsList($change),
        );
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function tableOptions(array $change): string
    {
        $options = trim($this->tableOptionsList($change));

        if ($options === '') {
            throw new UserError('Nothing to change.');
        }

        // Converting rewrites every text column to the new collation, which
        // is what changing a table's collation is almost always meant to do.
        $collation = trim((string) ($change['collation'] ?? ''));

        if ($collation !== '' && ($change['convert'] ?? false) === true) {
            $charset = strstr(self::collation($collation), '_', true) ?: 'utf8mb4';
            $options = trim(str_replace(' COLLATE '.self::collation($collation), '', ' '.$options));
            // CONVERT is an alteration, not a table option: it takes a comma.
            $options = ($options === '' ? '' : $options.', ').'CONVERT TO CHARACTER SET '.$charset.' COLLATE '.self::collation($collation);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function tableOptionsList(array $change): string
    {
        $options = '';
        $engine = trim((string) ($change['engine'] ?? ''));

        if ($engine !== '') {
            if (! in_array($engine, self::ENGINES, true)) {
                throw new UserError('Choose InnoDB, MyISAM or Aria.');
            }

            $options .= ' ENGINE='.$engine;
        }

        $collation = trim((string) ($change['collation'] ?? ''));

        if ($collation !== '') {
            $options .= ' COLLATE '.self::collation($collation);
        }

        if (array_key_exists('comment', $change) && $change['comment'] !== null) {
            $options .= ' COMMENT='.self::literal(mb_substr((string) $change['comment'], 0, 2048));
        }

        return $options;
    }

    /**
     * @param  array<string, array{name: string}>  $columns
     */
    private function indexDefinition(mixed $index, array $columns): string
    {
        if (! is_array($index)) {
            throw new UserError('Describe the index.');
        }

        $kind = (string) ($index['kind'] ?? 'index');
        $parts = is_array($index['columns'] ?? null) ? array_values($index['columns']) : [];

        if ($parts === []) {
            throw new UserError('Choose the columns to index.');
        }

        $list = implode(', ', array_map(function (mixed $part) use ($columns): string {
            $name = is_array($part) ? ($part['name'] ?? null) : $part;
            $length = is_array($part) ? trim((string) ($part['length'] ?? '')) : '';
            $sql = Identifier::quote($this->existingColumn($name, $columns)['name']);

            if ($length !== '') {
                if (! ctype_digit($length) || (int) $length < 1) {
                    throw new UserError('An index prefix length must be a positive number.');
                }

                $sql .= '('.(int) $length.')';
            }

            return $sql;
        }, $parts));

        if ($kind === 'primary') {
            return 'PRIMARY KEY ('.$list.')';
        }

        $name = trim((string) ($index['name'] ?? ''));
        $quotedName = $name === '' ? '' : ' '.Identifier::quote($name);

        return match ($kind) {
            'index' => 'INDEX'.$quotedName.' ('.$list.')',
            'unique' => 'UNIQUE INDEX'.$quotedName.' ('.$list.')',
            'fulltext' => 'FULLTEXT INDEX'.$quotedName.' ('.$list.')',
            default => throw new UserError('Choose index, unique, primary or fulltext.'),
        };
    }

    private function dropIndex(string $database, string $table, mixed $name): string
    {
        foreach ($this->catalog->indexes($database, $table) as $index) {
            if ($index['name'] === $name) {
                return $index['primary'] ? 'DROP PRIMARY KEY' : 'DROP INDEX '.Identifier::quote($index['name']);
            }
        }

        throw new UserError('That index does not exist.', 404);
    }

    /**
     * @param  array<string, array{name: string}>  $columns
     */
    private function position(mixed $position, array $columns): string
    {
        if ($position === null || $position === '' || $position === 'last') {
            return '';
        }

        if ($position === 'first') {
            return ' FIRST';
        }

        if (is_array($position) && isset($position['after'])) {
            return ' AFTER '.Identifier::quote($this->existingColumn($position['after'], $columns)['name']);
        }

        throw new UserError('Unknown column position.');
    }

    /**
     * @param  array<string, array{name: string}>  $columns
     * @return array{name: string}
     */
    private function existingColumn(mixed $name, array $columns): array
    {
        if (! is_string($name) || ! isset($columns[$name])) {
            throw new UserError('That column does not exist.', 404);
        }

        return $columns[$name];
    }

    private static function defaultLiteral(string $type, string $value): string
    {
        // Numbers are written as numbers, so a BIT or numeric default is the
        // number meant rather than the bytes of a string.
        if ($type === 'BIT') {
            if (! ctype_digit($value)) {
                throw new UserError('A BIT default must be a whole number.');
            }

            return $value;
        }

        if (in_array($type, self::TYPES['numeric'], true)) {
            if (! is_numeric($value)) {
                throw new UserError(sprintf('"%s" is not a number.', $value));
            }

            return $value;
        }

        return self::literal($value);
    }

    /**
     * A string literal that is safe whatever the connection's settings:
     * backslashes and quotes are escaped, and nothing else is special.
     */
    private static function literal(string $value): string
    {
        return "'".str_replace(['\\', "'", "\0"], ['\\\\', "''", '\\0'], $value)."'";
    }

    private static function collation(string $collation): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $collation) !== 1) {
            throw new UserError('That is not a collation name.');
        }

        return $collation;
    }
}
