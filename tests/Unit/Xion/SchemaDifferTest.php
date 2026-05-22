<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SchemaDiffer;
use PHPUnit\Framework\TestCase;

/**
 * Pin the diff plan that `cli/schemaDiff.php` (ADR-0009 / FT17) feeds
 * to the operator. The CLI is a thin PDO+stdout wrapper around this
 * class, so coverage on `SchemaDiffer` is what protects the diff
 * semantics from drifting.
 */
final class SchemaDifferTest extends TestCase
{
    public function testInSyncWhenLiveMatchesDefinition(): void
    {
        $tables = [
            'todos' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'title' => ['type' => 'varchar:255'],
            ]],
        ];
        $live = [
            'todos' => ['columns' => [
                'id' => ['data_type' => 'bigint'],
                'title' => ['data_type' => 'varchar'],
            ]],
        ];

        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertTrue($diff['inSync']);
        self::assertSame([], $diff['newTables']);
        self::assertSame([], $diff['newColumns']);
        self::assertSame([], $diff['warnings']);
    }

    public function testNewTableEmitsMysqlCreateTable(): void
    {
        $tables = [
            'audit_log' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'created_at' => ['type' => 'datetime-now'],
                'message' => ['type' => 'text'],
            ]],
        ];
        $diff = SchemaDiffer::diff([], $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertFalse($diff['inSync']);
        self::assertArrayHasKey('audit_log', $diff['newTables']);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS audit_log', $diff['newTables']['audit_log']);
        self::assertStringContainsString('ENGINE=InnoDB', $diff['newTables']['audit_log']);
        self::assertStringEndsWith(';', $diff['newTables']['audit_log']);
    }

    public function testNewTableEmitsSqliteCreateTable(): void
    {
        $tables = [
            'audit_log' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'message' => ['type' => 'text'],
            ]],
        ];
        $diff = SchemaDiffer::diff([], $tables, SchemaDiffer::DRIVER_SQLITE);

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS audit_log', $diff['newTables']['audit_log']);
        self::assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $diff['newTables']['audit_log']);
        self::assertStringNotContainsString('ENGINE=InnoDB', $diff['newTables']['audit_log']);
    }

    public function testNewColumnEmitsMysqlAlterTable(): void
    {
        $tables = [
            'todos' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'is_completed' => ['type' => 'bool', 'default' => 0],
            ]],
        ];
        $live = [
            'todos' => ['columns' => [
                'id' => ['data_type' => 'bigint'],
            ]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertCount(1, $diff['newColumns']);
        $entry = $diff['newColumns'][0];
        self::assertSame('todos', $entry['table']);
        self::assertSame('is_completed', $entry['column']);
        self::assertSame(
            'ALTER TABLE todos ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0;',
            $entry['sql']
        );
    }

    public function testNewColumnEmitsSqliteAlterTable(): void
    {
        $tables = [
            'todos' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'title' => ['type' => 'varchar:255'],
            ]],
        ];
        $live = [
            'todos' => ['columns' => [
                'id' => ['data_type' => 'INTEGER'],
            ]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_SQLITE);

        self::assertCount(1, $diff['newColumns']);
        self::assertSame(
            'ALTER TABLE todos ADD COLUMN title TEXT NOT NULL;',
            $diff['newColumns'][0]['sql']
        );
    }

    public function testBoolDefaultPropagatesToNewColumn(): void
    {
        // Regression: the `default` key on a bool column must be
        // reflected in the ALTER TABLE — operators who add a new bool
        // with default=1 must not get a NULL-after-default-0 surprise.
        $tables = [
            'todos' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
                'is_archived' => ['type' => 'bool', 'default' => 1],
            ]],
        ];
        $live = [
            'todos' => ['columns' => ['id' => ['data_type' => 'bigint']]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertSame(
            'ALTER TABLE todos ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 1;',
            $diff['newColumns'][0]['sql']
        );
    }

    public function testExtraColumnInLiveEmitsWarningNotSql(): void
    {
        $tables = [
            'users' => ['columns' => [
                'id' => ['type' => 'pk-bigint'],
            ]],
        ];
        $live = [
            'users' => ['columns' => [
                'id' => ['data_type' => 'bigint'],
                'legacy_phone' => ['data_type' => 'varchar'],
            ]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertTrue($diff['inSync']);
        self::assertCount(1, $diff['warnings']);
        self::assertStringContainsString('users.legacy_phone', $diff['warnings'][0]);
        self::assertStringContainsString('hand-written', $diff['warnings'][0]);
    }

    public function testExtraTableInLiveEmitsWarningNotSql(): void
    {
        $tables = [];
        $live = [
            'legacy_audit' => ['columns' => ['id' => ['data_type' => 'bigint']]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertTrue($diff['inSync']);
        self::assertCount(1, $diff['warnings']);
        self::assertStringContainsString('table `legacy_audit`', $diff['warnings'][0]);
    }

    public function testMultipleWarningsAccumulate(): void
    {
        $tables = [
            'users' => ['columns' => ['id' => ['type' => 'pk-bigint']]],
        ];
        $live = [
            'users' => ['columns' => [
                'id' => ['data_type' => 'bigint'],
                'extra1' => ['data_type' => 'varchar'],
                'extra2' => ['data_type' => 'varchar'],
            ]],
            'orphan' => ['columns' => ['id' => ['data_type' => 'bigint']]],
        ];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);

        self::assertCount(3, $diff['warnings']);
    }

    public function testInSyncFlagIsFalseWhenAnyAddIsPresent(): void
    {
        $tables = ['todos' => ['columns' => ['id' => ['type' => 'pk-bigint']]]];
        $live = [];
        $diff = SchemaDiffer::diff($live, $tables, SchemaDiffer::DRIVER_MYSQL);
        self::assertFalse($diff['inSync']);
    }
}
