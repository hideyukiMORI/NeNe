<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\LoginHistory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LoginHistory.
 */
final class LoginHistoryTest extends TestCase
{
    private PDO $db;
    private LoginHistory $lh;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE login_history (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                success    TINYINT(1)   NOT NULL DEFAULT 0,
                ip_address VARCHAR(45)  NOT NULL DEFAULT \'\',
                user_agent TEXT         NOT NULL DEFAULT \'\',
                reason     VARCHAR(100) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->lh = new LoginHistory($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->lh->record('user-1', true);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $this->lh->record('user-1', false, '1.2.3.4', 'Mozilla/5.0', 'bad_password');
        $row = $this->lh->lastFailure('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip_address']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('bad_password', $row['reason']);
    }

    public function testRecordThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lh->record('', true);
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsEntriesInReverseOrder(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false);
        $recent = $this->lh->recent('user-1');
        $this->assertCount(2, $recent);
        // Most recent first
        $this->assertSame(0, (int)$recent[0]['success']);
    }

    public function testRecentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lh->record('user-1', false);
        }
        $this->assertCount(3, $this->lh->recent('user-1', 3));
    }

    public function testRecentReturnsEmptyForNonExistentUser(): void
    {
        $this->assertSame([], $this->lh->recent('nobody'));
    }

    // ── lastSuccess / lastFailure ─────────────────────────────────────────────

    public function testLastSuccessReturnsLatestSuccessfulLogin(): void
    {
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', true, '1.2.3.4');
        $row = $this->lh->lastSuccess('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip_address']);
    }

    public function testLastSuccessReturnsNullIfNoSuccessfulLogin(): void
    {
        $this->lh->record('user-1', false);
        $this->assertNull($this->lh->lastSuccess('user-1'));
    }

    public function testLastFailureReturnsLatestFailedAttempt(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false, '9.9.9.9', '', 'bad_password');
        $row = $this->lh->lastFailure('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('9.9.9.9', $row['ip_address']);
    }

    public function testLastFailureReturnsNullIfNoFailedAttempt(): void
    {
        $this->lh->record('user-1', true);
        $this->assertNull($this->lh->lastFailure('user-1'));
    }

    // ── consecutiveFailures ───────────────────────────────────────────────────

    public function testConsecutiveFailuresReturnsZeroAfterSuccess(): void
    {
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', true); // resets
        $this->assertSame(0, $this->lh->consecutiveFailures('user-1'));
    }

    public function testConsecutiveFailuresCountsFailuresSinceLastSuccess(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', false);
        $this->assertSame(2, $this->lh->consecutiveFailures('user-1'));
    }

    public function testConsecutiveFailuresCountsAllWhenNeverSucceeded(): void
    {
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', false);
        $this->assertSame(3, $this->lh->consecutiveFailures('user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAllAttempts(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false);
        $this->assertSame(2, $this->lh->count('user-1'));
    }

    public function testCountSuccessesOnly(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false);
        $this->assertSame(1, $this->lh->count('user-1', true));
    }

    public function testCountFailuresOnly(): void
    {
        $this->lh->record('user-1', true);
        $this->lh->record('user-1', false);
        $this->lh->record('user-1', false);
        $this->assertSame(2, $this->lh->count('user-1', false));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        // Insert a very old entry directly
        $this->db->exec(
            "INSERT INTO login_history (user_id, success, created_at)
             VALUES ('user-old', 1, '2000-01-01 00:00:00')"
        );
        $this->lh->record('user-1', true); // recent
        $purged = $this->lh->purgeOlderThan(30);
        $this->assertGreaterThan(0, $purged);
        $this->assertSame(0, $this->lh->count('user-old'));
        $this->assertSame(1, $this->lh->count('user-1'));
    }
}
