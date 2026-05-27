<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ApiUsageLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiUsageLog.
 */
final class ApiUsageLogTest extends TestCase
{
    private PDO $db;
    private ApiUsageLog $log;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE api_usage_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                api_key     VARCHAR(255) NOT NULL,
                endpoint    VARCHAR(500) NOT NULL DEFAULT \'\',
                http_method VARCHAR(10)  NOT NULL DEFAULT \'\',
                status_code SMALLINT     NOT NULL DEFAULT 0,
                latency_ms  INT          NOT NULL DEFAULT 0,
                logged_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->log = new ApiUsageLog($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->log->record('key-1', '/api/posts', 'GET', 200, 45);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordMultipleEntries(): void
    {
        $this->log->record('key-1', '/api/posts', 'GET', 200, 10);
        $this->log->record('key-1', '/api/posts', 'POST', 201, 30);
        $this->log->record('key-2', '/api/users', 'GET', 200, 20);
        $this->assertSame(2, $this->log->countByKey('key-1'));
        $this->assertSame(1, $this->log->countByKey('key-2'));
    }

    public function testRecordNormalizesHttpMethodToUpperCase(): void
    {
        $this->log->record('key-1', '/api/posts', 'get', 200, 10);
        $recent = $this->log->recent('key-1', 1);
        $this->assertSame('GET', $recent[0]['http_method']);
    }

    public function testRecordThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->record('');
    }

    // ── countByKey ────────────────────────────────────────────────────────────

    public function testCountByKeyReturnsZeroForUnknown(): void
    {
        $this->assertSame(0, $this->log->countByKey('unknown'));
    }

    public function testCountByKeyWithSinceFilter(): void
    {
        // Insert an old entry manually
        $old = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO api_usage_log (api_key, logged_at) VALUES ('key-1', '{$old}')");
        $this->log->record('key-1'); // recent entry

        $this->assertSame(2, $this->log->countByKey('key-1'));
        $this->assertSame(1, $this->log->countByKey('key-1', since: '-1 hour'));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsEntriesNewestFirst(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->log->record('key-1', "/api/item/{$i}", 'GET', 200, $i * 10);
        }
        $rows = $this->log->recent('key-1', 3);
        $this->assertCount(3, $rows);
        // Newest first — highest id first
        $this->assertGreaterThan((int)$rows[1]['id'], (int)$rows[0]['id']);
    }

    public function testRecentReturnsEmptyForUnknownKey(): void
    {
        $this->assertSame([], $this->log->recent('unknown'));
    }

    // ── topEndpoints ─────────────────────────────────────────────────────────

    public function testTopEndpointsRanksByCallCount(): void
    {
        $this->log->record('key-1', '/a', 'GET', 200);
        $this->log->record('key-1', '/b', 'GET', 200);
        $this->log->record('key-1', '/b', 'GET', 200);
        $this->log->record('key-1', '/c', 'GET', 200);
        $this->log->record('key-1', '/c', 'GET', 200);
        $this->log->record('key-1', '/c', 'GET', 200);

        $top = $this->log->topEndpoints('key-1', 2);
        $this->assertCount(2, $top);
        $this->assertSame('/c', $top[0]['endpoint']);
        $this->assertSame(3, $top[0]['calls']);
        $this->assertSame('/b', $top[1]['endpoint']);
    }

    public function testTopEndpointsReturnsEmptyWhenNoData(): void
    {
        $this->assertSame([], $this->log->topEndpoints('key-1'));
    }

    // ── errorRate ─────────────────────────────────────────────────────────────

    public function testErrorRateReturnsZeroWhenNoData(): void
    {
        $this->assertSame(0.0, $this->log->errorRate('key-1'));
    }

    public function testErrorRateCalculation(): void
    {
        $this->log->record('key-1', '/a', 'GET', 200);
        $this->log->record('key-1', '/a', 'GET', 200);
        $this->log->record('key-1', '/a', 'GET', 500);
        $this->log->record('key-1', '/a', 'GET', 404);
        // 2 errors out of 4 = 0.5
        $this->assertSame(0.5, $this->log->errorRate('key-1'));
    }

    public function testErrorRateZeroWhenAllSuccessful(): void
    {
        $this->log->record('key-1', '/a', 'GET', 200);
        $this->log->record('key-1', '/a', 'GET', 201);
        $this->assertSame(0.0, $this->log->errorRate('key-1'));
    }

    // ── avgLatency ────────────────────────────────────────────────────────────

    public function testAvgLatencyReturnsZeroWhenNoData(): void
    {
        $this->assertSame(0.0, $this->log->avgLatency('key-1'));
    }

    public function testAvgLatencyCalculation(): void
    {
        $this->log->record('key-1', '/a', 'GET', 200, 100);
        $this->log->record('key-1', '/a', 'GET', 200, 200);
        $this->assertSame(150.0, $this->log->avgLatency('key-1'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $old = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO api_usage_log (api_key, logged_at) VALUES ('key-1', '{$old}')");
        $this->db->exec("INSERT INTO api_usage_log (api_key, logged_at) VALUES ('key-1', '{$old}')");
        $this->log->record('key-1'); // recent

        $deleted = $this->log->purgeOlderThan(1);
        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->log->countByKey('key-1'));
    }

    public function testPurgeReturnsZeroWhenNothingOld(): void
    {
        $this->log->record('key-1');
        $this->assertSame(0, $this->log->purgeOlderThan(1));
    }
}
