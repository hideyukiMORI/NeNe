<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TrustScore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TrustScore.
 */
final class TrustScoreTest extends TestCase
{
    private PDO $db;
    private TrustScore $ts;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE trust_scores (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                delta       INTEGER      NOT NULL,
                reason      VARCHAR(255) NOT NULL DEFAULT \'\',
                recorded_by VARCHAR(255) NOT NULL DEFAULT \'\',
                recorded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ts = new TrustScore($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordStoresDelta(): void
    {
        $this->ts->record('user-1', -20, 'suspicious_login', 'security-bot');
        $this->assertSame(-20, $this->ts->current('user-1'));
    }

    public function testRecordAccumulatesDeltas(): void
    {
        $this->ts->record('user-1', 10);
        $this->ts->record('user-1', -5);
        $this->ts->record('user-1', 20);
        $this->assertSame(25, $this->ts->current('user-1'));
    }

    public function testRecordThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->record('', 10);
    }

    public function testRecordThrowsOnZeroDelta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->record('user-1', 0);
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->ts->current('nobody'));
    }

    public function testCurrentHandlesNegativeScore(): void
    {
        $this->ts->record('user-1', -100);
        $this->ts->record('user-1', 30);
        $this->assertSame(-70, $this->ts->current('user-1'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $this->ts->record('user-1', 10, 'first');
        $this->ts->record('user-1', -5, 'second');
        $history = $this->ts->history('user-1');
        $this->assertCount(2, $history);
        $this->assertSame(-5, (int)$history[0]['delta']);
    }

    public function testHistoryReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->ts->history('nobody'));
    }

    public function testHistoryRespectsLimit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->ts->record('user-1', 1);
        }
        $history = $this->ts->history('user-1', 5);
        $this->assertCount(5, $history);
    }

    public function testHistoryIncludesReasonAndRecordedBy(): void
    {
        $this->ts->record('user-1', -10, 'vpn_detected', 'fraud-engine');
        $history = $this->ts->history('user-1');
        $this->assertSame('vpn_detected', $history[0]['reason']);
        $this->assertSame('fraud-engine', $history[0]['recorded_by']);
    }

    // ── below ─────────────────────────────────────────────────────────────────

    public function testBelowReturnsUsersBelowThreshold(): void
    {
        $this->ts->record('user-1', -50);
        $this->ts->record('user-2', 20);
        $this->ts->record('user-3', -10);
        $risky = $this->ts->below(0);
        $this->assertCount(2, $risky);
    }

    public function testBelowReturnsEmptyWhenNoneQualify(): void
    {
        $this->ts->record('user-1', 100);
        $this->assertSame([], $this->ts->below(0));
    }

    public function testBelowOrdersByScoreAscending(): void
    {
        $this->ts->record('user-1', -10);
        $this->ts->record('user-2', -30);
        $risky = $this->ts->below(0);
        $this->assertSame('user-2', $risky[0]['user_id']);
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetDeletesAllEntries(): void
    {
        $this->ts->record('user-1', 10);
        $this->ts->record('user-1', -5);
        $count = $this->ts->reset('user-1');
        $this->assertGreaterThan(0, $count);
        $this->assertSame(0, $this->ts->current('user-1'));
    }

    public function testResetReturnsZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->ts->reset('nobody'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $past = (new \DateTimeImmutable())->modify('-31 days')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO trust_scores (user_id, delta, recorded_at)
            VALUES ('user-1', 10, '{$past}')");
        $count = $this->ts->purgeOlderThan(30);
        $this->assertGreaterThan(0, $count);
    }

    public function testPurgeOlderThanKeepsRecentEntries(): void
    {
        $this->ts->record('user-1', 10);
        $this->ts->purgeOlderThan(30);
        $this->assertSame(10, $this->ts->current('user-1'));
    }
}
