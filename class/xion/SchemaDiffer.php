<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Schema-diff engine — compares a live database introspection against
 * {@see SchemaDefinition::tables()} and emits the SQL needed to converge
 * the live DB to the definition. Adopted via ADR-0009 (#409) as
 * Option C: operator-applied diff, no auto-apply.
 *
 * Scope (per ADR-0009):
 *
 * - Adds emitted as SQL:
 *   - new tables (full `CREATE TABLE` via {@see SchemaCompiler})
 *   - new columns (`ALTER TABLE … ADD COLUMN …`)
 *   - new indexes (`CREATE INDEX … ON … (…)`) — MySQL + SQLite (#421)
 * - Drops, renames, type changes, constraint changes: **warning only**.
 *   The operator hand-writes those — destructive ops should never be
 *   produced by an automated tool without data semantics.
 *
 * `SchemaDiffer` is pure-static and DB-agnostic. The CLI
 * (`cli/schemaDiff.php`) handles introspection via PDO and feeds
 * the resulting arrays into {@see diff()}.
 */
final class SchemaDiffer
{
    public const DRIVER_MYSQL  = 'mysql';
    public const DRIVER_SQLITE = 'sqlite';

    /**
     * Compute the diff plan.
     *
     * `$liveTables` shape:
     *
     *     [
     *       'users' => [
     *           'columns' => ['id' => [...], ...],
     *           'indexes' => ['users_user_id_unique' => ['user_id']],
     *       ],
     *       'todos' => ['columns' => [...], 'indexes' => [...]],
     *     ]
     *
     * `$definitionTables` shape: `SchemaDefinition::tables()` output.
     *
     * @param array<string,array<string,mixed>> $liveTables       Introspected live schema.
     * @param array<string,array<string,mixed>> $definitionTables Source of truth.
     * @param string                            $driver           `mysql` or `sqlite`.
     *
     * @return array{
     *     newTables: array<string,string>,
     *     newColumns: array<int,array{table:string,column:string,sql:string}>,
     *     newIndexes: array<int,array{table:string,index:string,sql:string}>,
     *     warnings: array<int,string>,
     *     inSync: bool
     * }
     */
    public static function diff(array $liveTables, array $definitionTables, string $driver): array
    {
        $newTables  = [];
        $newColumns = [];
        $newIndexes = [];
        $warnings   = [];

        foreach ($definitionTables as $tableName => $tableSpec) {
            if (!isset($liveTables[$tableName])) {
                $newTables[$tableName] = self::createTableSql($tableName, $tableSpec, $driver);
                continue;
            }
            $liveCols = $liveTables[$tableName]['columns'] ?? [];
            $defCols  = $tableSpec['columns'] ?? [];
            foreach ($defCols as $colName => $colSpec) {
                if (!isset($liveCols[$colName])) {
                    $newColumns[] = [
                        'table'  => $tableName,
                        'column' => $colName,
                        'sql'    => self::addColumnSql($tableName, $colName, $colSpec, $driver),
                    ];
                }
            }
            foreach (array_keys($liveCols) as $liveCol) {
                if (!isset($defCols[$liveCol])) {
                    $warnings[] = sprintf(
                        'column `%s.%s` exists in the live database but not in SchemaDefinition — drop SQL must be hand-written (ADR-0009 destructive-op rule).',
                        $tableName,
                        $liveCol
                    );
                }
            }
            $liveIndexes = $liveTables[$tableName]['indexes'] ?? [];
            $defIndexes  = $tableSpec['indexes'] ?? [];
            foreach ($defIndexes as $indexName => $indexCols) {
                if (!isset($liveIndexes[$indexName])) {
                    $newIndexes[] = [
                        'table' => $tableName,
                        'index' => $indexName,
                        'sql'   => self::createIndexSql($tableName, $indexName, $indexCols),
                    ];
                }
            }
            foreach (array_keys($liveIndexes) as $liveIndex) {
                if (!isset($defIndexes[$liveIndex])) {
                    $warnings[] = sprintf(
                        'index `%s` on `%s` exists in the live database but not in SchemaDefinition — drop SQL must be hand-written (ADR-0009 destructive-op rule).',
                        $liveIndex,
                        $tableName
                    );
                }
            }
        }
        foreach (array_keys($liveTables) as $liveTable) {
            if (!isset($definitionTables[$liveTable])) {
                $warnings[] = sprintf(
                    'table `%s` exists in the live database but not in SchemaDefinition — drop SQL must be hand-written (ADR-0009 destructive-op rule).',
                    $liveTable
                );
            }
        }

        return [
            'newTables'  => $newTables,
            'newColumns' => $newColumns,
            'newIndexes' => $newIndexes,
            'warnings'   => $warnings,
            'inSync'     => $newTables === [] && $newColumns === [] && $newIndexes === [],
        ];
    }

    /**
     * @param array<string,mixed> $tableSpec
     */
    private static function createTableSql(string $name, array $tableSpec, string $driver): string
    {
        return match ($driver) {
            self::DRIVER_SQLITE => SchemaCompiler::sqliteCreateTable($name, $tableSpec) . ';',
            default             => SchemaCompiler::mysqlCreateTable($name, $tableSpec) . ';',
        };
    }

    /**
     * @param array<string,mixed> $colSpec
     */
    private static function addColumnSql(string $table, string $column, array $colSpec, string $driver): string
    {
        $columnDdl = $driver === self::DRIVER_SQLITE
            ? SchemaCompiler::sqliteColumn($column, $colSpec)
            : SchemaCompiler::mysqlColumn($column, $colSpec);
        return 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $columnDdl . ';';
    }

    /**
     * `CREATE INDEX` syntax is shared verbatim between MySQL and SQLite,
     * so the same emitter works for both drivers. Unique-constraint
     * indexes from the `unique` key of {@see SchemaDefinition::tables()}
     * are part of the original `CREATE TABLE` and are not re-emitted
     * here — only entries from the `indexes` key are subject to the
     * diff path.
     *
     * @param array<int,string> $columns
     */
    private static function createIndexSql(string $table, string $index, array $columns): string
    {
        return 'CREATE INDEX ' . $index . ' ON ' . $table . ' (' . implode(', ', $columns) . ');';
    }
}
