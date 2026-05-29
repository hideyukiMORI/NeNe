<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\HealthCheck;
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
{
    private PDO $pdo;
    private HealthCheck $hc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE health_checks (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                service       VARCHAR(100) NOT NULL,
                status        VARCHAR(20)  NOT NULL,
                response_time INTEGER      NOT NULL DEFAULT 0,
                message       TEXT         NULL,
                checked_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->hc = new HealthCheck($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->hc->record('database', HealthCheck::STATUS_OK, 120);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresFields(): void
    {
        $id   = $this->hc->record('database', HealthCheck::STATUS_DEGRADED, 850, 'High latency');
        $rows = $this->hc->recent('database', 1);
        $this->assertCount(1, $rows);
        $this->assertSame('database', $rows[0]['service']);
        $this->assertSame(HealthCheck::STATUS_DEGRADED, $rows[0]['status']);
        $this->assertSame(850, (int)$rows[0]['response_time']);
        $this->assertSame('High latency', $rows[0]['message']);
    }

    public function testRecordThrowsOnEmptyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hc->record('', HealthCheck::STATUS_OK);
    }

    public function testRecordThrowsOnInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hc->record('database', 'unknown');
    }

    // ── latestStatus ──────────────────────────────────────────────────────────

    public function testLatestStatusReturnsNewest(): void
    {
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $this->hc->record('database', HealthCheck::STATUS_FAIL, 0);
        $this->assertSame(HealthCheck::STATUS_FAIL, $this->hc->latestStatus('database'));
    }

    public function testLatestStatusReturnsNullWhenNone(): void
    {
        $this->assertNull($this->hc->latestStatus('unknown'));
    }

    // ── latestAll ─────────────────────────────────────────────────────────────

    public function testLatestAllReturnsMapOfAllServices(): void
    {
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $this->hc->record('cache', HealthCheck::STATUS_DEGRADED, 500);
        $this->hc->record('queue', HealthCheck::STATUS_FAIL, 0);
        $all = $this->hc->latestAll();
        $this->assertArrayHasKey('database', $all);
        $this->assertArrayHasKey('cache', $all);
        $this->assertArrayHasKey('queue', $all);
        $this->assertSame(HealthCheck::STATUS_OK, $all['database']);
        $this->assertSame(HealthCheck::STATUS_FAIL, $all['queue']);
    }

    public function testLatestAllReturnsLatestPerService(): void
    {
        $this->hc->record('database', HealthCheck::STATUS_FAIL, 0);
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $all = $this->hc->latestAll();
        $this->assertSame(HealthCheck::STATUS_OK, $all['database']);
    }

    public function testLatestAllReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->hc->latestAll());
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsNewestFirst(): void
    {
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $this->hc->record('database', HealthCheck::STATUS_FAIL, 0);
        $rows = $this->hc->recent('database', 10);
        $this->assertSame(HealthCheck::STATUS_FAIL, $rows[0]['status']);
    }

    public function testRecentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        }
        $this->assertCount(3, $this->hc->recent('database', 3));
    }

    // ── avgResponseTime ───────────────────────────────────────────────────────

    public function testAvgResponseTimeCalculatesCorrectly(): void
    {
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $this->hc->record('database', HealthCheck::STATUS_OK, 200);
        $this->hc->record('database', HealthCheck::STATUS_OK, 300);
        $avg = $this->hc->avgResponseTime('database', 3);
        $this->assertEqualsWithDelta(200.0, $avg, 0.01);
    }

    public function testAvgResponseTimeReturnsZeroWhenNone(): void
    {
        $this->assertSame(0.0, $this->hc->avgResponseTime('unknown'));
    }

    // ── failureRate ───────────────────────────────────────────────────────────

    public function testFailureRateCalculatesCorrectly(): void
    {
        $this->hc->record('queue', HealthCheck::STATUS_OK, 50);
        $this->hc->record('queue', HealthCheck::STATUS_FAIL, 0);
        $rate = $this->hc->failureRate('queue', 2);
        $this->assertEqualsWithDelta(0.5, $rate, 0.001);
    }

    public function testFailureRateReturnsZeroWhenNone(): void
    {
        $this->assertSame(0.0, $this->hc->failureRate('unknown'));
    }

    public function testFailureRateIsOneForAllFails(): void
    {
        $this->hc->record('queue', HealthCheck::STATUS_FAIL, 0);
        $this->hc->record('queue', HealthCheck::STATUS_FAIL, 0);
        $rate = $this->hc->failureRate('queue', 2);
        $this->assertEqualsWithDelta(1.0, $rate, 0.001);
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        $this->pdo->exec("INSERT INTO health_checks (service, status, response_time, checked_at)
                          VALUES ('database', 'ok', 100, '2020-01-01 00:00:00')");
        $this->hc->record('database', HealthCheck::STATUS_OK, 100);
        $deleted = $this->hc->purgeOlderThan('2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->hc->recent('database'));
    }
}
