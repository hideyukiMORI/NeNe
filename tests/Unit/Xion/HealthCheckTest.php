<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\HealthCheck;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HealthCheck.
 */
final class HealthCheckTest extends TestCase
{
    private PDO $db;
    private HealthCheck $hc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE health_checks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                service    VARCHAR(255) NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'ok\',
                message    TEXT         NOT NULL DEFAULT \'\',
                checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE health_check_current (
                service    VARCHAR(255) NOT NULL PRIMARY KEY,
                status     VARCHAR(20)  NOT NULL DEFAULT \'ok\',
                message    TEXT         NOT NULL DEFAULT \'\',
                checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->hc = new HealthCheck($this->db);
    }

    // ── report ────────────────────────────────────────────────────────────────

    public function testReportSetsCurrentStatus(): void
    {
        $this->hc->report('db', 'ok');
        $this->assertSame('ok', $this->hc->status('db'));
    }

    public function testReportAppendsHistory(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('db', 'degraded', 'Slow');
        $this->assertCount(2, $this->hc->history('db'));
    }

    public function testReportUpdatesCurrentStatus(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('db', 'down', 'Connection refused');
        $this->assertSame('down', $this->hc->status('db'));
    }

    public function testReportWithMessage(): void
    {
        $this->hc->report('svc', 'degraded', 'High latency');
        $cur = $this->hc->current('svc');
        $this->assertNotNull($cur);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('High latency', $cur['message']);
    }

    public function testReportThrowsOnEmptyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hc->report('', 'ok');
    }

    public function testReportThrowsOnInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hc->report('db', 'broken');
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusReturnsNullForUnknownService(): void
    {
        $this->assertNull($this->hc->status('nonexistent'));
    }

    public function testStatusReturnsDegraded(): void
    {
        $this->hc->report('cache', 'degraded');
        $this->assertSame('degraded', $this->hc->status('cache'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllServices(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('cache', 'ok');
        $this->hc->report('email', 'down');
        $this->assertCount(3, $this->hc->all());
    }

    public function testAllReturnsEmptyWhenNoReports(): void
    {
        $this->assertSame([], $this->hc->all());
    }

    // ── isHealthy ─────────────────────────────────────────────────────────────

    public function testIsHealthyTrueWhenAllOk(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('cache', 'ok');
        $this->assertTrue($this->hc->isHealthy());
    }

    public function testIsHealthyFalseWhenAnyNotOk(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('cache', 'down');
        $this->assertFalse($this->hc->isHealthy());
    }

    public function testIsHealthyTrueWhenNoServices(): void
    {
        $this->assertTrue($this->hc->isHealthy());
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $this->hc->report('db', 'ok');
        $this->hc->report('db', 'degraded');
        $history = $this->hc->history('db');
        $this->assertSame('degraded', $history[0]['status']);
        $this->assertSame('ok', $history[1]['status']);
    }

    public function testHistoryRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->hc->report('db', 'ok');
        }
        $this->assertCount(3, $this->hc->history('db', 3));
    }

    public function testHistoryReturnsEmptyForUnknownService(): void
    {
        $this->assertSame([], $this->hc->history('nonexistent'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $this->hc->report('db', 'ok');
        $this->db->exec("UPDATE health_checks SET checked_at = datetime('now', '-8 days')");
        $this->assertSame(1, $this->hc->purgeOlderThan(7));
    }

    public function testPurgeOlderThanPreservesRecentEntries(): void
    {
        $this->hc->report('db', 'ok');
        $this->assertSame(0, $this->hc->purgeOlderThan(7));
    }
}
