<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\KpiTracker;
use PDO;
use PHPUnit\Framework\TestCase;

final class KpiTrackerTest extends TestCase
{
    private PDO $pdo;
    private KpiTracker $kpi;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE kpi_definitions (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slug       VARCHAR(100) NOT NULL,
                label      TEXT         NOT NULL,
                target     INTEGER      NULL,
                period     VARCHAR(50)  NOT NULL DEFAULT \'\',
                unit       VARCHAR(50)  NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE kpi_data_points (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                definition_id INTEGER  NOT NULL,
                value         INTEGER  NOT NULL,
                note          TEXT     NULL,
                recorded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->kpi = new KpiTracker($this->pdo);
    }

    // ── define ────────────────────────────────────────────────────────────────

    public function testDefineReturnsId(): void
    {
        $id = $this->kpi->define('revenue', 'Monthly Revenue', 100000, '2026-05');
        $this->assertGreaterThan(0, $id);
    }

    public function testDefineStoresFields(): void
    {
        $id  = $this->kpi->define('revenue', 'Monthly Revenue', 100000, '2026-05', 'cents');
        $row = $this->kpi->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('revenue', $row['slug']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Monthly Revenue', $row['label']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100000, (int)$row['target']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-05', $row['period']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('cents', $row['unit']);
    }

    public function testDefineWithNullTarget(): void
    {
        $id  = $this->kpi->define('views', 'Page Views');
        $row = $this->kpi->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['target']);
    }

    public function testDefineThrowsOnEmptySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kpi->define('', 'Revenue');
    }

    public function testDefineThrowsOnEmptyLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kpi->define('revenue', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->kpi->get(9999));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindBySlugAndPeriod(): void
    {
        $id  = $this->kpi->define('revenue', 'Revenue', null, '2026-05');
        $row = $this->kpi->find('revenue', '2026-05');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
    }

    public function testFindReturnsNullForMissingSlug(): void
    {
        $this->assertNull($this->kpi->find('nonexistent', '2026-05'));
    }

    public function testFindDistinguishesPeriods(): void
    {
        $this->kpi->define('revenue', 'Revenue Q1', null, '2026-Q1');
        $this->kpi->define('revenue', 'Revenue Q2', null, '2026-Q2');
        $row = $this->kpi->find('revenue', '2026-Q1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Revenue Q1', $row['label']);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsTrueOnSuccess(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->assertTrue($this->kpi->record($id, 42000, 'Mid-month'));
    }

    public function testRecordReturnsFalseForMissingDefinition(): void
    {
        $this->assertFalse($this->kpi->record(9999, 42000));
    }

    // ── latest ────────────────────────────────────────────────────────────────

    public function testLatestReturnsNullWhenNoDataPoints(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->assertNull($this->kpi->latest($id));
    }

    public function testLatestReturnsNewestValue(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->kpi->record($id, 42000);
        $this->kpi->record($id, 63000);
        $this->assertSame(63000, $this->kpi->latest($id));
    }

    // ── progress ──────────────────────────────────────────────────────────────

    public function testProgressReturnsActualAndTarget(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue', 100000);
        $this->kpi->record($id, 63000);
        $p = $this->kpi->progress($id);
        $this->assertSame(63000, $p['actual']);
        $this->assertSame(100000, $p['target']);
        $this->assertSame(63.0, $p['pct']);
    }

    public function testProgressNullWhenNoDataPoints(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue', 100000);
        $p  = $this->kpi->progress($id);
        $this->assertNull($p['actual']);
        $this->assertNull($p['pct']);
    }

    public function testProgressNullPctWhenNoTarget(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->kpi->record($id, 50000);
        $p = $this->kpi->progress($id);
        $this->assertSame(50000, $p['actual']);
        $this->assertNull($p['pct']);
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllDataPoints(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->kpi->record($id, 10000);
        $this->kpi->record($id, 20000);
        $this->kpi->record($id, 30000);
        $this->assertCount(3, $this->kpi->history($id));
    }

    public function testHistoryReturnsEmptyWhenNoRecords(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->assertSame([], $this->kpi->history($id));
    }

    // ── forPeriod ─────────────────────────────────────────────────────────────

    public function testForPeriodFilters(): void
    {
        $this->kpi->define('revenue', 'Revenue', null, '2026-Q2');
        $this->kpi->define('churn', 'Churn Rate', null, '2026-Q2');
        $this->kpi->define('revenue', 'Revenue', null, '2026-Q1');
        $this->assertCount(2, $this->kpi->forPeriod('2026-Q2'));
        $this->assertCount(1, $this->kpi->forPeriod('2026-Q1'));
    }

    // ── setTarget ─────────────────────────────────────────────────────────────

    public function testSetTargetUpdatesValue(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue', 50000);
        $this->assertTrue($this->kpi->setTarget($id, 120000));
        $row = $this->kpi->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(120000, (int)$row['target']);
    }

    public function testSetTargetToNull(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue', 50000);
        $this->kpi->setTarget($id, null);
        $row = $this->kpi->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['target']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesDefinitionAndDataPoints(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->kpi->record($id, 42000);
        $this->assertTrue($this->kpi->delete($id));
        $this->assertNull($this->kpi->get($id));
        $this->assertSame([], $this->kpi->history($id));
    }

    public function testDeleteReturnsFalseForMissingDefinition(): void
    {
        $this->assertFalse($this->kpi->delete(9999));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldDataPoints(): void
    {
        $id = $this->kpi->define('revenue', 'Revenue');
        $this->pdo->exec(
            "INSERT INTO kpi_data_points (definition_id, value, recorded_at)
             VALUES ({$id}, 10000, '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO kpi_data_points (definition_id, value, recorded_at)
             VALUES ({$id}, 20000, '2026-05-01 00:00:00')"
        );
        $deleted = $this->kpi->purgeOlderThan($id, '2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->kpi->history($id));
    }
}
