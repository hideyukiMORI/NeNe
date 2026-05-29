<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\WaitlistEntry;
use PDO;
use PHPUnit\Framework\TestCase;

final class WaitlistEntryTest extends TestCase
{
    private PDO $pdo;
    private WaitlistEntry $wl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE waitlist_entries (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                list_name   VARCHAR(100) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'waiting\',
                invited_at  DATETIME     NULL,
                accepted_at DATETIME     NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (list_name, user_id)
            )
        ');
        $this->wl = new WaitlistEntry($this->pdo);
    }

    // ── join ──────────────────────────────────────────────────────────────────

    public function testJoinReturnsId(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testJoinStoresCorrectly(): void
    {
        $id    = $this->wl->join('launch', 'user-1');
        $entry = $this->wl->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('launch', $entry['list_name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $entry['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WaitlistEntry::STATUS_WAITING, $entry['status']);
    }

    public function testJoinThrowsOnDuplicateUser(): void
    {
        $this->wl->join('launch', 'user-1');
        $this->expectException(\RuntimeException::class);
        $this->wl->join('launch', 'user-1');
    }

    public function testJoinThrowsOnEmptyListName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->join('', 'user-1');
    }

    public function testJoinThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->join('launch', '');
    }

    // ── findEntry / find ──────────────────────────────────────────────────────

    public function testFindEntryReturnsNullWhenNotOnList(): void
    {
        $this->assertNull($this->wl->findEntry('launch', 'nobody'));
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->wl->find(9999));
    }

    // ── invite ────────────────────────────────────────────────────────────────

    public function testInviteChangesWaitingToInvited(): void
    {
        $id     = $this->wl->join('launch', 'user-1');
        $result = $this->wl->invite($id);
        $this->assertTrue($result);

        $entry = $this->wl->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WaitlistEntry::STATUS_INVITED, $entry['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($entry['invited_at']);
    }

    public function testInviteReturnsFalseWhenAlreadyAccepted(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->wl->invite($id);
        $this->wl->accept($id);
        $this->assertFalse($this->wl->invite($id));
    }

    public function testInviteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wl->invite(9999));
    }

    // ── accept ────────────────────────────────────────────────────────────────

    public function testAcceptChangesInvitedToAccepted(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->wl->invite($id);
        $result = $this->wl->accept($id);
        $this->assertTrue($result);

        $entry = $this->wl->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WaitlistEntry::STATUS_ACCEPTED, $entry['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($entry['accepted_at']);
    }

    public function testAcceptReturnsFalseWhenInWaitingState(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->assertFalse($this->wl->accept($id));
    }

    public function testAcceptReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wl->accept(9999));
    }

    // ── leave ─────────────────────────────────────────────────────────────────

    public function testLeaveFromWaitingState(): void
    {
        $id     = $this->wl->join('launch', 'user-1');
        $result = $this->wl->leave($id);
        $this->assertTrue($result);
        $entry = $this->wl->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WaitlistEntry::STATUS_LEFT, $entry['status']);
    }

    public function testLeaveFromInvitedState(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->wl->invite($id);
        $this->assertTrue($this->wl->leave($id));
    }

    public function testLeaveReturnsFalseWhenAlreadyAccepted(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->wl->invite($id);
        $this->wl->accept($id);
        $this->assertFalse($this->wl->leave($id));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesEntry(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->assertTrue($this->wl->delete($id));
        $this->assertNull($this->wl->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wl->delete(9999));
    }

    // ── inviteNext ────────────────────────────────────────────────────────────

    public function testInviteNextInvitesFirstNWaiting(): void
    {
        $id1 = $this->wl->join('launch', 'user-1');
        $id2 = $this->wl->join('launch', 'user-2');
        $this->wl->join('launch', 'user-3');

        $invited = $this->wl->inviteNext('launch', 2);
        $this->assertCount(2, $invited);
        $invitedIds = array_map('intval', array_column($invited, 'id'));
        $this->assertContains($id1, $invitedIds);
        $this->assertContains($id2, $invitedIds);
        $this->assertSame(1, $this->wl->countWaiting('launch'));
    }

    public function testInviteNextReturnsEmptyWhenNoWaiting(): void
    {
        $this->assertSame([], $this->wl->inviteNext('launch', 3));
    }

    // ── listWaiting ───────────────────────────────────────────────────────────

    public function testListWaitingReturnsOnlyWaiting(): void
    {
        $id1 = $this->wl->join('launch', 'user-1');
        $id2 = $this->wl->join('launch', 'user-2');
        $this->wl->invite($id1);

        $waiting = $this->wl->listWaiting('launch');
        $this->assertCount(1, $waiting);
        $this->assertSame($id2, (int)$waiting[0]['id']);
    }

    public function testListWaitingReturnsOldestFirst(): void
    {
        $id1 = $this->wl->join('launch', 'user-1');
        $id2 = $this->wl->join('launch', 'user-2');
        $list = $this->wl->listWaiting('launch');
        $this->assertSame($id1, (int)$list[0]['id']);
        $this->assertSame($id2, (int)$list[1]['id']);
    }

    // ── countWaiting ─────────────────────────────────────────────────────────

    public function testCountWaiting(): void
    {
        $this->wl->join('launch', 'user-1');
        $id2 = $this->wl->join('launch', 'user-2');
        $this->wl->invite($id2);
        $this->assertSame(1, $this->wl->countWaiting('launch'));
    }

    public function testCountWaitingReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->wl->countWaiting('launch'));
    }

    // ── position ─────────────────────────────────────────────────────────────

    public function testPositionReturnsQueuePosition(): void
    {
        $this->wl->join('launch', 'user-1');
        $this->wl->join('launch', 'user-2');
        $this->wl->join('launch', 'user-3');
        $this->assertSame(1, $this->wl->position('launch', 'user-1'));
        $this->assertSame(2, $this->wl->position('launch', 'user-2'));
        $this->assertSame(3, $this->wl->position('launch', 'user-3'));
    }

    public function testPositionReturnsNullWhenNotWaiting(): void
    {
        $this->assertNull($this->wl->position('launch', 'nobody'));
    }

    public function testPositionReturnsNullAfterInvite(): void
    {
        $id = $this->wl->join('launch', 'user-1');
        $this->wl->invite($id);
        $this->assertNull($this->wl->position('launch', 'user-1'));
    }
}
