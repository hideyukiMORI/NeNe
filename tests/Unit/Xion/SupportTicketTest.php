<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\SupportTicket;
use PDO;
use PHPUnit\Framework\TestCase;

final class SupportTicketTest extends TestCase
{
    private PDO $pdo;
    private SupportTicket $st;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE support_tickets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                subject     VARCHAR(255) NOT NULL,
                body        TEXT         NOT NULL DEFAULT \'\',
                status      VARCHAR(20)  NOT NULL DEFAULT \'open\',
                priority    VARCHAR(20)  NOT NULL DEFAULT \'normal\',
                assigned_to VARCHAR(255) NOT NULL DEFAULT \'\',
                resolved_at DATETIME     NULL,
                closed_at   DATETIME     NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE ticket_replies (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id  INTEGER      NOT NULL,
                author_id  VARCHAR(255) NOT NULL,
                body       TEXT         NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->st = new SupportTicket($this->pdo);
    }

    // ── open ──────────────────────────────────────────────────────────────────

    public function testOpenReturnsId(): void
    {
        $id = $this->st->open('user-1', 'Cannot log in');
        $this->assertGreaterThan(0, $id);
    }

    public function testOpenCreatesWithOpenStatus(): void
    {
        $id  = $this->st->open('user-1', 'Subject', 'Body', SupportTicket::PRIORITY_HIGH);
        $row = $this->st->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::STATUS_OPEN, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::PRIORITY_HIGH, $row['priority']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
    }

    public function testOpenThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->open('', 'Subject');
    }

    public function testOpenThrowsOnEmptySubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->open('user-1', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->st->find(9999));
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function testAssignSetsStaffAndInProgress(): void
    {
        $id = $this->st->open('user-1', 'Help!');
        $result = $this->st->assign($id, 'staff-1');
        $this->assertTrue($result);

        $row = $this->st->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::STATUS_IN_PROGRESS, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('staff-1', $row['assigned_to']);
    }

    public function testAssignReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->st->assign(9999, 'staff-1'));
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveTransitionsFromOpen(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $result = $this->st->resolve($id);
        $this->assertTrue($result);

        $row = $this->st->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::STATUS_RESOLVED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['resolved_at']);
    }

    public function testResolveTransitionsFromInProgress(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->st->assign($id, 'staff-1');
        $this->assertTrue($this->st->resolve($id));
    }

    public function testResolveReturnsFalseForAlreadyResolved(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->st->resolve($id);
        $this->assertFalse($this->st->resolve($id));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseFromOpen(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $result = $this->st->close($id);
        $this->assertTrue($result);

        $row = $this->st->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::STATUS_CLOSED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['closed_at']);
    }

    public function testCloseReturnsFalseForAlreadyClosed(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->st->close($id);
        $this->assertFalse($this->st->close($id));
    }

    // ── reopen ────────────────────────────────────────────────────────────────

    public function testReopenFromResolved(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->st->resolve($id);
        $result = $this->st->reopen($id);
        $this->assertTrue($result);

        $row = $this->st->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SupportTicket::STATUS_OPEN, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['resolved_at']);
    }

    public function testReopenFromClosed(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->st->close($id);
        $this->assertTrue($this->st->reopen($id));
    }

    public function testReopenReturnsFalseForOpen(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->assertFalse($this->st->reopen($id));
    }

    // ── addReply / replies ────────────────────────────────────────────────────

    public function testAddReplyStoresReply(): void
    {
        $id  = $this->st->open('user-1', 'Issue');
        $rid = $this->st->addReply($id, 'staff-1', 'We are looking into it.');
        $this->assertGreaterThan(0, $rid);

        $replies = $this->st->replies($id);
        $this->assertCount(1, $replies);
        $this->assertSame('We are looking into it.', $replies[0]['body']);
        $this->assertSame('staff-1', $replies[0]['author_id']);
    }

    public function testAddReplyThrowsOnEmptyBody(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $this->expectException(\InvalidArgumentException::class);
        $this->st->addReply($id, 'staff-1', '');
    }

    public function testRepliesAreOrderedOldestFirst(): void
    {
        $id = $this->st->open('user-1', 'Issue');
        $r1 = $this->st->addReply($id, 'user-1', 'First');
        $r2 = $this->st->addReply($id, 'staff-1', 'Second');

        $replies = $this->st->replies($id);
        $this->assertSame($r1, (int)$replies[0]['id']);
        $this->assertSame($r2, (int)$replies[1]['id']);
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsUserTickets(): void
    {
        $this->st->open('user-1', 'A');
        $this->st->open('user-1', 'B');
        $this->st->open('user-2', 'C');

        $rows = $this->st->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    // ── openTickets ───────────────────────────────────────────────────────────

    public function testOpenTicketsExcludesResolvedAndClosed(): void
    {
        $id1 = $this->st->open('user-1', 'Open');
        $id2 = $this->st->open('user-1', 'Resolved');
        $id3 = $this->st->open('user-1', 'Closed');
        $this->st->resolve($id2);
        $this->st->close($id3);

        $rows = $this->st->openTickets();
        $this->assertCount(1, $rows);
        $this->assertSame($id1, (int)$rows[0]['id']);
    }

    // ── countByStatus ─────────────────────────────────────────────────────────

    public function testCountByStatusGroupsCorrectly(): void
    {
        $this->st->open('user-1', 'A');
        $id2 = $this->st->open('user-1', 'B');
        $this->st->resolve($id2);

        $counts = $this->st->countByStatus();
        $this->assertSame(1, $counts[SupportTicket::STATUS_OPEN]);
        $this->assertSame(1, $counts[SupportTicket::STATUS_RESOLVED]);
    }
}
