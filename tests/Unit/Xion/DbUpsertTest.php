<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\DbUpsert;
use PDO;
use PHPUnit\Framework\TestCase;

final class DbUpsertTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE kv_store (
                key_name    VARCHAR(100) PRIMARY KEY,
                value       TEXT         NOT NULL,
                hit_count   INT          NOT NULL DEFAULT 0,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (key_name)
            )
        ');
    }

    // ── insert (no conflict) ──────────────────────────────────────────────────

    public function testRunInsertsNewRow(): void
    {
        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'foo', 'value' => 'bar'],
            conflictCols: ['key_name'],
            updateCols: ['value'],
            updateExprs: ['updated_at' => 'CURRENT_TIMESTAMP'],
        );

        $stmt = $this->pdo->query("SELECT * FROM kv_store WHERE key_name = 'foo'");
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('bar', $row['value']);
    }

    // ── update on conflict (updateCols) ───────────────────────────────────────

    public function testRunUpdatesOnConflictViaUpdateCols(): void
    {
        $this->pdo->exec("INSERT INTO kv_store (key_name, value) VALUES ('foo', 'old')");

        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'foo', 'value' => 'new'],
            conflictCols: ['key_name'],
            updateCols: ['value'],
        );

        $stmt = $this->pdo->query("SELECT value FROM kv_store WHERE key_name = 'foo'");
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('new', $stmt->fetchColumn());
    }

    // ── update via updateExprs (raw SQL) ──────────────────────────────────────

    public function testRunUpdatesViaUpdateExprs(): void
    {
        $this->pdo->exec("INSERT INTO kv_store (key_name, value) VALUES ('foo', 'bar')");

        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'foo', 'value' => 'bar'],
            conflictCols: ['key_name'],
            updateExprs: ['updated_at' => 'CURRENT_TIMESTAMP'],
        );

        $stmt = $this->pdo->query("SELECT updated_at FROM kv_store WHERE key_name = 'foo'");
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertNotNull($stmt->fetchColumn());
    }

    // ── no update clause (DO NOTHING on conflict) ─────────────────────────────

    public function testRunDoesNothingOnConflictWhenNoUpdateCols(): void
    {
        $this->pdo->exec("INSERT INTO kv_store (key_name, value) VALUES ('foo', 'original')");

        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'foo', 'value' => 'ignored'],
            conflictCols: ['key_name'],
        );

        $stmt = $this->pdo->query("SELECT value FROM kv_store WHERE key_name = 'foo'");
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('original', $stmt->fetchColumn());
    }

    // ── idempotent — same call twice leaves one row ───────────────────────────

    public function testRunIsIdempotent(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DbUpsert::run(
                $this->pdo,
                table: 'kv_store',
                data: ['key_name' => 'counter', 'value' => 'x'],
                conflictCols: ['key_name'],
                updateCols: ['value'],
            );
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM kv_store');
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    // ── multiple data columns ─────────────────────────────────────────────────

    public function testRunWithMultipleDataColumns(): void
    {
        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'multi', 'value' => 'first', 'hit_count' => 1],
            conflictCols: ['key_name'],
            updateCols: ['value', 'hit_count'],
        );

        DbUpsert::run(
            $this->pdo,
            table: 'kv_store',
            data: ['key_name' => 'multi', 'value' => 'second', 'hit_count' => 2],
            conflictCols: ['key_name'],
            updateCols: ['value', 'hit_count'],
        );

        $stmt = $this->pdo->query("SELECT value, hit_count FROM kv_store WHERE key_name = 'multi'");
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('second', $row['value']);
        $this->assertSame(2, (int)$row['hit_count']);
    }
}
