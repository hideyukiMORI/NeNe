<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Heartbeat;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Heartbeat.
 */
final class HeartbeatTest extends TestCase
{
    private PDO $db;
    private Heartbeat $hb;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE heartbeats (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                service   VARCHAR(150) NOT NULL,
                last_beat DATETIME     NOT NULL,
                UNIQUE (service)
            )
        ');
        $this->hb = new Heartbeat($this->db);
    }

    public function testBeatAndLastBeat(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        $this->assertSame('2026-05-29 12:00:00', $this->hb->lastBeat('cron'));
    }

    public function testBeatIsUpsert(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        $this->hb->beat('cron', '2026-05-29 12:05:00');
        $this->assertSame('2026-05-29 12:05:00', $this->hb->lastBeat('cron'));
        $this->assertCount(1, $this->hb->all());
    }

    public function testLastBeatNullWhenUnseen(): void
    {
        $this->assertNull($this->hb->lastBeat('ghost'));
    }

    public function testIsAliveWithinWindow(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        // 200s later, window 300s → still alive
        $this->assertTrue($this->hb->isAlive('cron', 300, '2026-05-29 12:03:20'));
    }

    public function testIsAliveBoundaryInclusive(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        // exactly 300s later, window 300s → last_beat == cutoff → alive (>=)
        $this->assertTrue($this->hb->isAlive('cron', 300, '2026-05-29 12:05:00'));
        // 301s later → cutoff is 12:00:01, last_beat 12:00:00 < cutoff → dead
        $this->assertFalse($this->hb->isAlive('cron', 300, '2026-05-29 12:05:01'));
    }

    public function testIsAliveFalseWhenUnseen(): void
    {
        $this->assertFalse($this->hb->isAlive('ghost', 300, '2026-05-29 12:00:00'));
    }

    public function testStaleListsServicesPastWindow(): void
    {
        $this->hb->beat('fresh', '2026-05-29 12:04:00');
        $this->hb->beat('old', '2026-05-29 11:00:00');
        // asOf 12:05:00, window 300s → cutoff 12:00:00; 'old' is stale, 'fresh' is not
        $stale = $this->hb->stale(300, '2026-05-29 12:05:00');
        $this->assertCount(1, $stale);
        $this->assertSame('old', $stale[0]['service']);
    }

    public function testAliveListsServicesWithinWindowFreshestFirst(): void
    {
        $this->hb->beat('a', '2026-05-29 12:04:00');
        $this->hb->beat('b', '2026-05-29 12:04:30');
        $this->hb->beat('old', '2026-05-29 11:00:00');
        $alive = $this->hb->alive(300, '2026-05-29 12:05:00');
        $this->assertCount(2, $alive);
        $this->assertSame('b', $alive[0]['service']); // freshest first
        $this->assertSame('a', $alive[1]['service']);
    }

    public function testAliveAndStaleArePartition(): void
    {
        $this->hb->beat('a', '2026-05-29 12:04:00');
        $this->hb->beat('old', '2026-05-29 11:00:00');
        $alive = $this->hb->alive(300, '2026-05-29 12:05:00');
        $stale = $this->hb->stale(300, '2026-05-29 12:05:00');
        $this->assertSame(2, count($alive) + count($stale));
    }

    public function testForget(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        $this->hb->forget('cron');
        $this->assertNull($this->hb->lastBeat('cron'));
    }

    public function testForgetMissingIsNoop(): void
    {
        $this->hb->forget('ghost');
        $this->assertSame([], $this->hb->all());
    }

    public function testBeatRejectsEmptyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hb->beat('  ', '2026-05-29 12:00:00');
    }

    public function testIsAliveRejectsZeroWindow(): void
    {
        $this->hb->beat('cron', '2026-05-29 12:00:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->hb->isAlive('cron', 0, '2026-05-29 12:00:00');
    }
}
