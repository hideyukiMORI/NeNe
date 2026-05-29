<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\UserTier;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserTierTest extends TestCase
{
    private PDO $pdo;
    private UserTier $t;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_tiers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                tier        VARCHAR(50)  NOT NULL,
                assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reason      TEXT         NULL,
                UNIQUE (user_id)
            )
        ');
        $this->pdo->exec('
            CREATE TABLE user_tier_history (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                tier        VARCHAR(50)  NOT NULL,
                assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reason      TEXT         NULL
            )
        ');
        $this->t = new UserTier($this->pdo);
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function testAssignSetsCurrent(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->assertSame('bronze', $this->t->current('u1'));
    }

    public function testAssignOverwritesCurrent(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u1', 'silver', 'Reached threshold');
        $this->assertSame('silver', $this->t->current('u1'));
    }

    public function testAssignOnlyOneCurrentRowPerUser(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u1', 'silver');
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM user_tiers WHERE user_id = 'u1'");
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testAssignAppendsToHistory(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u1', 'silver');
        $history = $this->t->history('u1');
        $this->assertCount(2, $history);
    }

    public function testAssignThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->assign('', 'bronze');
    }

    public function testAssignThrowsOnEmptyTier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->assign('u1', '');
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsNullWhenNotAssigned(): void
    {
        $this->assertNull($this->t->current('u999'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsMostRecentFirst(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u1', 'silver');
        $this->t->assign('u1', 'gold');

        $history = $this->t->history('u1');
        $this->assertSame('gold', $history[0]['tier']);
        $this->assertSame('silver', $history[1]['tier']);
        $this->assertSame('bronze', $history[2]['tier']);
    }

    public function testHistoryStoresReason(): void
    {
        $this->t->assign('u1', 'gold', 'Loyalty milestone');
        $history = $this->t->history('u1');
        $this->assertSame('Loyalty milestone', $history[0]['reason']);
    }

    public function testHistoryReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->t->history('u999'));
    }

    // ── usersInTier ───────────────────────────────────────────────────────────

    public function testUsersInTierReturnsMatchingUsers(): void
    {
        $this->t->assign('u1', 'silver');
        $this->t->assign('u2', 'silver');
        $this->t->assign('u3', 'gold');

        $users = $this->t->usersInTier('silver');
        $this->assertCount(2, $users);
        $this->assertContains('u1', $users);
        $this->assertContains('u2', $users);
    }

    public function testUsersInTierReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->t->usersInTier('platinum'));
    }

    // ── countByTier ───────────────────────────────────────────────────────────

    public function testCountByTierGroupsCorrectly(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u2', 'bronze');
        $this->t->assign('u3', 'gold');

        $counts = $this->t->countByTier();
        $this->assertSame(2, $counts['bronze']);
        $this->assertSame(1, $counts['gold']);
    }

    public function testCountByTierReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->t->countByTier());
    }

    // ── hasEverHad ────────────────────────────────────────────────────────────

    public function testHasEverHadReturnsTrueForPastTier(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->assign('u1', 'silver');
        $this->assertTrue($this->t->hasEverHad('u1', 'bronze'));
    }

    public function testHasEverHadReturnsFalseForNeverHeld(): void
    {
        $this->t->assign('u1', 'silver');
        $this->assertFalse($this->t->hasEverHad('u1', 'platinum'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesCurrentTier(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->assertTrue($this->t->remove('u1'));
        $this->assertNull($this->t->current('u1'));
    }

    public function testRemoveKeepsHistory(): void
    {
        $this->t->assign('u1', 'bronze');
        $this->t->remove('u1');
        $history = $this->t->history('u1');
        $this->assertCount(1, $history);
    }

    public function testRemoveReturnsFalseWhenNotAssigned(): void
    {
        $this->assertFalse($this->t->remove('u999'));
    }
}
