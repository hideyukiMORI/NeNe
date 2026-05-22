<?php

declare(strict_types=1);

namespace Nene\Xion;

use InvalidArgumentException;

/**
 * Compile {@see SchemaDefinition} into MySQL and SQLite DDL.
 *
 * Outputs are deterministic strings (one DDL statement per element of
 * each array, no trailing semicolon, no trailing newline) so the result
 * can be byte-compared by drift tests and concatenated by the docker
 * init SQL generator. See ADR-0005.
 */
final class SchemaCompiler
{
    /**
     * Return the MySQL `CREATE TABLE` statements for every table in
     * {@see SchemaDefinition::tables()}, in declaration order.
     *
     * @return array<int,string>
     */
    public static function mysqlStatements(): array
    {
        return array_values(array_map(
            static fn (string $name, array $table): string => self::mysqlCreateTable($name, $table),
            array_keys(SchemaDefinition::tables()),
            SchemaDefinition::tables()
        ));
    }

    /**
     * Return the SQLite `CREATE TABLE` statements for every table in
     * {@see SchemaDefinition::tables()}, in declaration order.
     *
     * @return array<int,string>
     */
    public static function sqliteStatements(): array
    {
        return array_values(array_map(
            static fn (string $name, array $table): string => self::sqliteCreateTable($name, $table),
            array_keys(SchemaDefinition::tables()),
            SchemaDefinition::tables()
        ));
    }

    /**
     * Return SQLite `CREATE INDEX` statements (indexes outside the
     * `CREATE TABLE` body, as SQLite requires them).
     *
     * @return array<int,string>
     */
    public static function sqliteIndexStatements(): array
    {
        $statements = [];
        foreach (SchemaDefinition::tables() as $tableName => $table) {
            foreach ($table['indexes'] ?? [] as $indexName => $columns) {
                $statements[] = 'CREATE INDEX IF NOT EXISTS ' . $indexName
                    . ' ON ' . $tableName
                    . ' (' . implode(', ', $columns) . ')';
            }
        }
        return $statements;
    }

    /**
     * Return SQLite trigger statements that simulate MySQL's
     * `ON UPDATE CURRENT_TIMESTAMP` behavior on every column whose
     * type is `datetime-touch`.
     *
     * @return array<int,string>
     */
    public static function sqliteTriggerStatements(): array
    {
        $statements = [];
        foreach (SchemaDefinition::tables() as $tableName => $table) {
            foreach ($table['columns'] ?? [] as $columnName => $column) {
                if (($column['type'] ?? '') !== 'datetime-touch') {
                    continue;
                }
                $statements[] = <<<SQL
CREATE TRIGGER IF NOT EXISTS {$tableName}_{$columnName}_trigger
AFTER UPDATE ON {$tableName}
FOR EACH ROW
WHEN NEW.{$columnName} = OLD.{$columnName}
BEGIN
    UPDATE {$tableName} SET {$columnName} = CURRENT_TIMESTAMP WHERE id = OLD.id;
END
SQL;
            }
        }
        return $statements;
    }

    /**
     * Build a `CREATE TABLE` statement for MySQL.
     *
     * @param string             $name  Table name.
     * @param array<string,mixed> $table Table definition.
     */
    public static function mysqlCreateTable(string $name, array $table): string
    {
        $lines = [];
        foreach ($table['columns'] as $columnName => $column) {
            $lines[] = '    ' . self::mysqlColumn($columnName, $column);
        }
        $lines[] = '    PRIMARY KEY (id)';
        foreach ($table['unique'] ?? [] as $uniqueName => $columns) {
            $lines[] = '    UNIQUE KEY ' . $uniqueName . ' (' . implode(', ', $columns) . ')';
        }
        foreach ($table['indexes'] ?? [] as $indexName => $columns) {
            $lines[] = '    KEY ' . $indexName . ' (' . implode(', ', $columns) . ')';
        }
        foreach ($table['foreign_keys'] ?? [] as $fkName => $fk) {
            $lines[] = '    CONSTRAINT ' . $fkName . PHP_EOL
                . '        FOREIGN KEY (' . implode(', ', $fk['columns']) . ') REFERENCES '
                . $fk['references'][0] . ' (' . implode(', ', $fk['references'][1]) . ')' . PHP_EOL
                . '        ON DELETE ' . $fk['on_delete'];
        }
        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (' . PHP_EOL
            . implode(',' . PHP_EOL, $lines) . PHP_EOL
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * Build a `CREATE TABLE` statement for SQLite.
     *
     * SQLite does not support `KEY` clauses inside `CREATE TABLE`; indexes
     * are emitted separately by {@see sqliteIndexStatements()}.
     *
     * @param string             $name  Table name.
     * @param array<string,mixed> $table Table definition.
     */
    public static function sqliteCreateTable(string $name, array $table): string
    {
        $lines = [];
        foreach ($table['columns'] as $columnName => $column) {
            $lines[] = '    ' . self::sqliteColumn($columnName, $column);
        }
        foreach ($table['unique'] ?? [] as $columns) {
            $lines[] = '    UNIQUE (' . implode(', ', $columns) . ')';
        }
        foreach ($table['foreign_keys'] ?? [] as $fk) {
            $lines[] = '    FOREIGN KEY (' . implode(', ', $fk['columns']) . ') REFERENCES '
                . $fk['references'][0] . ' (' . implode(', ', $fk['references'][1]) . ') '
                . 'ON DELETE ' . $fk['on_delete'];
        }
        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (' . PHP_EOL
            . implode(',' . PHP_EOL, $lines) . PHP_EOL
            . ')';
    }

    /**
     * @param array<string,mixed> $column
     */
    public static function mysqlColumn(string $name, array $column): string
    {
        $type = (string)($column['type'] ?? '');
        return match (true) {
            $type === 'pk-bigint'       => $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            $type === 'bigint'          => $name . ' BIGINT UNSIGNED NOT NULL',
            $type === 'text'            => $name . ' TEXT NOT NULL',
            $type === 'bool'            => $name . ' TINYINT(1) NOT NULL DEFAULT ' . (int)($column['default'] ?? 0),
            $type === 'datetime-now'    => $name . ' DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            $type === 'datetime-touch'  => $name . ' DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            str_starts_with($type, 'varchar:') => $name . ' VARCHAR(' . (int)substr($type, strlen('varchar:')) . ') NOT NULL',
            default => throw new InvalidArgumentException('Unknown column type for ' . $name . ': ' . $type),
        };
    }

    /**
     * @param array<string,mixed> $column
     */
    public static function sqliteColumn(string $name, array $column): string
    {
        $type = (string)($column['type'] ?? '');
        return match (true) {
            $type === 'pk-bigint'       => $name . ' INTEGER PRIMARY KEY AUTOINCREMENT',
            $type === 'bigint'          => $name . ' INTEGER NOT NULL',
            $type === 'text'            => $name . ' TEXT NOT NULL',
            $type === 'bool'            => $name . ' INTEGER NOT NULL DEFAULT ' . (int)($column['default'] ?? 0),
            $type === 'datetime-now'    => $name . ' TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP',
            $type === 'datetime-touch'  => $name . ' TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP',
            str_starts_with($type, 'varchar:') => $name . ' TEXT NOT NULL',
            default => throw new InvalidArgumentException('Unknown column type for ' . $name . ': ' . $type),
        };
    }
}
