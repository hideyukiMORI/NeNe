<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ProfileBadge;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileBadgeTest extends TestCase
{
    private PDO $pdo;
    private ProfileBadge $pb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE profile_badges (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                badge_key   VARCHAR(100) NOT NULL,
                awarded_by  VARCHAR(255) NULL,
                awarded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, badge_key)
            )
        ');
        $this->pb = new ProfileBadge($this->pdo);
    }

    // ── award ─────────────────────────────────────────────────────────────────

    public function testAwardReturnsTrueOnFirstAward(): void
    {
        $this->assertTrue($this->pb->award('user-1', 'first_purchase'));
    }

    public function testAwardIdempotentSecondCall(): void
    {
        $this->pb->award('user-1', 'first_purchase');
        $this->assertFalse($this->pb->award('user-1', 'first_purchase'));
    }

    public function testAwardStoresAwardedBy(): void
    {
        $this->pb->award('user-1', 'power_user', 'admin');
        $badges = $this->pb->userBadges('user-1');
        $this->assertSame('admin', $badges[0]['awarded_by']);
    }

    public function testAwardThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->award('', 'badge');
    }

    public function testAwardThrowsOnEmptyBadgeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->award('user-1', '');
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeRemovesBadge(): void
    {
        $this->pb->award('user-1', 'first_purchase');
        $this->assertTrue($this->pb->revoke('user-1', 'first_purchase'));
        $this->assertFalse($this->pb->hasBadge('user-1', 'first_purchase'));
    }

    public function testRevokeReturnsFalseIfNotHeld(): void
    {
        $this->assertFalse($this->pb->revoke('user-1', 'nonexistent'));
    }

    // ── hasBadge ──────────────────────────────────────────────────────────────

    public function testHasBadgeFalseBeforeAward(): void
    {
        $this->assertFalse($this->pb->hasBadge('user-1', 'power_user'));
    }

    public function testHasBadgeTrueAfterAward(): void
    {
        $this->pb->award('user-1', 'power_user');
        $this->assertTrue($this->pb->hasBadge('user-1', 'power_user'));
    }

    // ── userBadges ────────────────────────────────────────────────────────────

    public function testUserBadgesReturnsAllBadges(): void
    {
        $this->pb->award('user-1', 'badge_a');
        $this->pb->award('user-1', 'badge_b');
        $this->assertCount(2, $this->pb->userBadges('user-1'));
    }

    public function testUserBadgesEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->pb->userBadges('unknown'));
    }

    public function testUserBadgesIsolatedByUser(): void
    {
        $this->pb->award('user-1', 'badge_a');
        $this->pb->award('user-2', 'badge_b');
        $this->assertCount(1, $this->pb->userBadges('user-1'));
        $this->assertCount(1, $this->pb->userBadges('user-2'));
    }

    // ── usersWithBadge ────────────────────────────────────────────────────────

    public function testUsersWithBadgeReturnsUserIds(): void
    {
        $this->pb->award('user-1', 'power_user');
        $this->pb->award('user-2', 'power_user');
        $users = $this->pb->usersWithBadge('power_user');
        $this->assertCount(2, $users);
        $this->assertContains('user-1', $users);
        $this->assertContains('user-2', $users);
    }

    public function testUsersWithBadgeEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pb->usersWithBadge('nonexistent'));
    }

    // ── countForBadge ─────────────────────────────────────────────────────────

    public function testCountForBadgeCountsHolders(): void
    {
        $this->pb->award('user-1', 'power_user');
        $this->pb->award('user-2', 'power_user');
        $this->assertSame(2, $this->pb->countForBadge('power_user'));
    }

    public function testCountForBadgeZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pb->countForBadge('nonexistent'));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllRemovesAllBadges(): void
    {
        $this->pb->award('user-1', 'badge_a');
        $this->pb->award('user-1', 'badge_b');
        $count = $this->pb->revokeAll('user-1');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->pb->userBadges('user-1'));
    }

    public function testRevokeAllDoesNotAffectOtherUsers(): void
    {
        $this->pb->award('user-1', 'badge_a');
        $this->pb->award('user-2', 'badge_a');
        $this->pb->revokeAll('user-1');
        $this->assertTrue($this->pb->hasBadge('user-2', 'badge_a'));
    }
}
