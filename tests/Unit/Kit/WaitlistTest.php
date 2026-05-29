<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Waitlist;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Waitlist.
 */
final class WaitlistTest extends TestCase
{
    private PDO $db;
    private Waitlist $wl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE waitlist (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                list_name    VARCHAR(100) NOT NULL,
                user_id      VARCHAR(255) NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'waiting\',
                joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                invited_at   DATETIME     DEFAULT NULL,
                confirmed_at DATETIME     DEFAULT NULL,
                UNIQUE (list_name, user_id)
            )
        ');
        $this->wl = new Waitlist($this->db);
    }

    // ── join ──────────────────────────────────────────────────────────────────

    public function testJoinReturnsPosition(): void
    {
        $pos = $this->wl->join('beta', 'user-1');
        $this->assertSame(1, $pos);
    }

    public function testJoinIsIdempotent(): void
    {
        $this->wl->join('beta', 'user-1');
        $pos2 = $this->wl->join('beta', 'user-1');
        $this->assertSame(1, $pos2);
        $this->assertSame(1, $this->wl->count('beta'));
    }

    public function testJoinMultipleUsersGetIncreasingPositions(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->join('beta', 'user-2');
        $this->wl->join('beta', 'user-3');
        $this->assertSame(1, $this->wl->position('beta', 'user-1'));
        $this->assertSame(2, $this->wl->position('beta', 'user-2'));
        $this->assertSame(3, $this->wl->position('beta', 'user-3'));
    }

    public function testJoinThrowsOnEmptyListName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->join('', 'user-1');
    }

    public function testJoinThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->join('beta', '');
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusIsWaitingAfterJoin(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->assertSame('waiting', $this->wl->status('beta', 'user-1'));
    }

    public function testStatusReturnsNullForNonMember(): void
    {
        $this->assertNull($this->wl->status('beta', 'user-999'));
    }

    // ── inviteNext ────────────────────────────────────────────────────────────

    public function testInviteNextReturnsInvitedIds(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->join('beta', 'user-2');
        $this->wl->join('beta', 'user-3');
        $invited = $this->wl->inviteNext('beta', 2);
        $this->assertSame(['user-1', 'user-2'], $invited);
    }

    public function testInviteNextSetsStatusToInvited(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->inviteNext('beta', 1);
        $this->assertSame('invited', $this->wl->status('beta', 'user-1'));
    }

    public function testInviteNextDoesNotReinviteAlreadyInvited(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->join('beta', 'user-2');
        $this->wl->inviteNext('beta', 1);
        $invited = $this->wl->inviteNext('beta', 1);
        $this->assertSame(['user-2'], $invited);
    }

    public function testInviteNextReturnsEmptyForEmptyList(): void
    {
        $this->assertSame([], $this->wl->inviteNext('beta', 5));
    }

    // ── confirm ───────────────────────────────────────────────────────────────

    public function testConfirmSetsStatusToConfirmed(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->inviteNext('beta', 1);
        $this->assertTrue($this->wl->confirm('beta', 'user-1'));
        $this->assertSame('confirmed', $this->wl->status('beta', 'user-1'));
    }

    public function testConfirmReturnsFalseIfNotInvited(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->assertFalse($this->wl->confirm('beta', 'user-1'));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelWaitingUser(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->assertTrue($this->wl->cancel('beta', 'user-1'));
        $this->assertSame('cancelled', $this->wl->status('beta', 'user-1'));
    }

    public function testCancelInvitedUser(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->inviteNext('beta', 1);
        $this->assertTrue($this->wl->cancel('beta', 'user-1'));
        $this->assertSame('cancelled', $this->wl->status('beta', 'user-1'));
    }

    public function testCancelConfirmedUserReturnsFalse(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->inviteNext('beta', 1);
        $this->wl->confirm('beta', 'user-1');
        $this->assertFalse($this->wl->cancel('beta', 'user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForEmptyList(): void
    {
        $this->assertSame(0, $this->wl->count('beta'));
    }

    public function testCountByStatus(): void
    {
        $this->wl->join('beta', 'user-1');
        $this->wl->join('beta', 'user-2');
        $this->wl->inviteNext('beta', 1);
        $this->assertSame(1, $this->wl->count('beta', 'waiting'));
        $this->assertSame(1, $this->wl->count('beta', 'invited'));
    }
}
