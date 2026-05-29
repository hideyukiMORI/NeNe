<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CounterMetric;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CounterMetric.
 */
final class CounterMetricTest extends TestCase
{
    private PDO $db;
    private CounterMetric $cm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE counter_metrics (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                metric      VARCHAR(100) NOT NULL,
                dimension   VARCHAR(255) NOT NULL DEFAULT \'\',
                bucket_day  CHAR(10)     NOT NULL,
                bucket_hour TINYINT      NOT NULL DEFAULT 0,
                value       BIGINT       NOT NULL DEFAULT 0,
                UNIQUE (metric, dimension, bucket_day, bucket_hour)
            )
        ');
        $this->cm = new CounterMetric($this->db);
    }

    // ── increment ─────────────────────────────────────────────────────────────

    public function testIncrementByOne(): void
    {
        $this->cm->increment('page.views');
        $this->assertSame(1, $this->cm->total('page.views'));
    }

    public function testIncrementByCustomAmount(): void
    {
        $this->cm->increment('page.views', '', 5);
        $this->assertSame(5, $this->cm->total('page.views'));
    }

    public function testIncrementAccumulates(): void
    {
        $this->cm->increment('page.views');
        $this->cm->increment('page.views');
        $this->cm->increment('page.views');
        $this->assertSame(3, $this->cm->total('page.views'));
    }

    public function testIncrementWithDimension(): void
    {
        $this->cm->increment('api.calls', 'GET:/users', 3);
        $this->cm->increment('api.calls', 'POST:/users', 2);
        $this->assertSame(5, $this->cm->total('api.calls'));
        $this->assertSame(3, $this->cm->total('api.calls', null, 'GET:/users'));
        $this->assertSame(2, $this->cm->total('api.calls', null, 'POST:/users'));
    }

    public function testIncrementThrowsOnEmptyMetric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->increment('');
    }

    public function testIncrementThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->increment('page.views', '', 0);
    }

    // ── total ─────────────────────────────────────────────────────────────────

    public function testTotalReturnsZeroForUnknownMetric(): void
    {
        $this->assertSame(0, $this->cm->total('unknown'));
    }

    public function testTotalWithSinceFilter(): void
    {
        // Inject an old bucket
        $old = (new \DateTimeImmutable())->modify('-5 days')->format('Y-m-d');
        $this->db->exec("INSERT INTO counter_metrics (metric, bucket_day, bucket_hour, value)
                         VALUES ('ev', '{$old}', 0, 100)");
        $this->cm->increment('ev', '', 10); // today

        $this->assertSame(110, $this->cm->total('ev'));
        $this->assertSame(10, $this->cm->total('ev', '-1 day'));
    }

    // ── dailySeries ───────────────────────────────────────────────────────────

    public function testDailySeriesGroupsByDay(): void
    {
        $today     = (new \DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d');

        $this->db->exec("INSERT INTO counter_metrics (metric, bucket_day, bucket_hour, value)
                         VALUES ('ev', '{$yesterday}', 10, 5), ('ev', '{$yesterday}', 14, 3)");
        $this->cm->increment('ev', '', 2); // today

        $series = $this->cm->dailySeries('ev', 7);
        $this->assertGreaterThanOrEqual(2, count($series));
        // Find yesterday entry
        $yday = array_filter($series, static fn ($r) => $r['date'] === $yesterday);
        $this->assertSame(8, array_values($yday)[0]['value']); // 5+3
    }

    public function testDailySeriesReturnsEmptyForUnknownMetric(): void
    {
        $this->assertSame([], $this->cm->dailySeries('unknown'));
    }

    // ── hourlySeries ──────────────────────────────────────────────────────────

    public function testHourlySeriesGroupsByHour(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->db->exec("INSERT INTO counter_metrics (metric, bucket_day, bucket_hour, value)
                         VALUES ('ev', '{$today}', 9, 10), ('ev', '{$today}', 10, 20)");

        $series = $this->cm->hourlySeries('ev', 24);
        $this->assertGreaterThanOrEqual(2, count($series));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetDeletesAllBuckets(): void
    {
        $this->cm->increment('page.views', '', 10);
        $deleted = $this->cm->reset('page.views');
        $this->assertGreaterThan(0, $deleted);
        $this->assertSame(0, $this->cm->total('page.views'));
    }

    public function testResetByDimensionOnlyDeletesMatchingDimension(): void
    {
        $this->cm->increment('api.calls', 'GET:/users', 5);
        $this->cm->increment('api.calls', 'POST:/users', 3);
        $this->cm->reset('api.calls', 'GET:/users');
        $this->assertSame(3, $this->cm->total('api.calls'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldBuckets(): void
    {
        $old = (new \DateTimeImmutable())->modify('-10 days')->format('Y-m-d');
        $this->db->exec("INSERT INTO counter_metrics (metric, bucket_day, bucket_hour, value)
                         VALUES ('ev', '{$old}', 0, 100)");
        $this->cm->increment('ev'); // today

        $deleted = $this->cm->purgeOlderThan(7);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->cm->total('ev'));
    }
}
