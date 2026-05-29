<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\UserBan;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserBan.
 */
final class UserBanTest extends TestCase
{
    private PDO $db;
    private UserBan $ub;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_bans (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                reason      TEXT         NOT NULL DEFAULT \'\',
                banned_by   VARCHAR(255) NOT NULL DEFAULT \'\',
                expires_at  DATETIME     DEFAULT NULL,
                lifted_at   DATETIME     DEFAULT NULL,
                lifted_by   VARCHAR(255) DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ub = new UserBan($this->db);
    }

    // ── ban ───────────────────────────────────────────────────────────────────

    public function testBanReturnsId(): void
    {
        $id = $this->ub->ban('user-1', 'Spam', 'admin-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testBanMakesUserBanned(): void
    {
        $this->ub->ban('user-1', 'Spam');
        $this->assertTrue($this->ub->isBanned('user-1'));
    }

    public function testBanWithExpiryIsBannedUntilExpiry(): void
    {
        $this->ub->ban('user-1', 'Temp', 'admin', new \DateTimeImmutable('+1 day'));
        $this->assertTrue($this->ub->isBanned('user-1'));
    }

    public function testBanThrowsOnExpiredExpiresAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ub->ban('user-1', 'Bad', 'admin', new \DateTimeImmutable('-1 hour'));
    }

    public function testBanThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ub->ban('');
    }

    public function testBanLiftsPreviousActiveBanBeforeCreatingNew(): void
    {
        $this->ub->ban('user-1', 'First');
        $this->ub->ban('user-1', 'Second');
        // Only one active ban at a time
        $this->assertSame(1, $this->countActiveBans('user-1'));
        // Total history = 2
        $this->assertSame(2, $this->ub->banCount('user-1'));
    }

    // ── unban ─────────────────────────────────────────────────────────────────

    public function testUnbanLiftsBan(): void
    {
        $this->ub->ban('user-1', 'Spam');
        $this->assertTrue($this->ub->unban('user-1', 'admin-1'));
        $this->assertFalse($this->ub->isBanned('user-1'));
    }

    public function testUnbanReturnsFalseIfNotBanned(): void
    {
        $this->assertFalse($this->ub->unban('user-1'));
    }

    public function testUnbanIsIdempotent(): void
    {
        $this->ub->ban('user-1', 'Spam');
        $this->ub->unban('user-1');
        $this->assertFalse($this->ub->unban('user-1')); // second call
    }

    // ── isBanned ──────────────────────────────────────────────────────────────

    public function testIsBannedReturnsFalseForNonBannedUser(): void
    {
        $this->assertFalse($this->ub->isBanned('user-1'));
    }

    public function testIsBannedReturnsFalseForExpiredBan(): void
    {
        // Insert an already-expired ban directly
        $this->db->exec(
            "INSERT INTO user_bans (user_id, reason, expires_at)
             VALUES ('user-1', 'Old', '2000-01-01 00:00:00')"
        );
        $this->assertFalse($this->ub->isBanned('user-1'));
    }

    public function testIsBannedReturnsFalseForLiftedBan(): void
    {
        $this->ub->ban('user-1', 'Spam');
        $this->ub->unban('user-1');
        $this->assertFalse($this->ub->isBanned('user-1'));
    }

    // ── activeBan ─────────────────────────────────────────────────────────────

    public function testActiveBanReturnsRowWhenBanned(): void
    {
        $this->ub->ban('user-1', 'Spam', 'admin-1');
        $row = $this->ub->activeBan('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Spam', $row['reason']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('admin-1', $row['banned_by']);
    }

    public function testActiveBanReturnsNullWhenNotBanned(): void
    {
        $this->assertNull($this->ub->activeBan('user-1'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllBans(): void
    {
        $this->ub->ban('user-1', 'First');
        $this->ub->ban('user-1', 'Second'); // lifts first, creates second
        $history = $this->ub->history('user-1');
        $this->assertCount(2, $history);
    }

    public function testHistoryReturnsEmptyForNonBannedUser(): void
    {
        $this->assertSame([], $this->ub->history('user-1'));
    }

    // ── banCount ──────────────────────────────────────────────────────────────

    public function testBanCountReturnsZeroForNoBans(): void
    {
        $this->assertSame(0, $this->ub->banCount('user-1'));
    }

    public function testBanCountIncludesLiftedBans(): void
    {
        $this->ub->ban('user-1');
        $this->ub->unban('user-1');
        $this->ub->ban('user-1');
        $this->assertSame(2, $this->ub->banCount('user-1'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function countActiveBans(string $userId): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_bans
             WHERE user_id = :uid AND lifted_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }
}
