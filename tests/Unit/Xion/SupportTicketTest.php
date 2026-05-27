<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SupportTicket;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SupportTicket.
 */
final class SupportTicketTest extends TestCase
{
    private PDO $db;
    private SupportTicket $st;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE support_tickets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                subject     VARCHAR(500) NOT NULL DEFAULT \'\',
                status      VARCHAR(20)  NOT NULL DEFAULT \'open\',
                assigned_to VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE support_ticket_replies (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id  INTEGER      NOT NULL,
                author_id  VARCHAR(255) NOT NULL,
                body       TEXT         NOT NULL,
                is_agent   TINYINT(1)   NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->st = new SupportTicket($this->db);
    }

    // ── open ──────────────────────────────────────────────────────────────────

    public function testOpenReturnsTicketId(): void
    {
        $id = $this->st->open('user-1', 'Login broken');
        $this->assertGreaterThan(0, $id);
    }

    public function testOpenCreatesOpenTicket(): void
    {
        $id     = $this->st->open('user-1', 'Subject');
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('open', $ticket['status']);
    }

    public function testOpenWithBodyCreatesInitialReply(): void
    {
        $id     = $this->st->open('user-1', 'Subject', 'My initial message');
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertCount(1, $ticket['replies']);
    }

    public function testOpenWithoutBodyCreatesNoReplies(): void
    {
        $id     = $this->st->open('user-1', 'Subject');
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertCount(0, $ticket['replies']);
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

    // ── reply ─────────────────────────────────────────────────────────────────

    public function testReplyAddsMessage(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->st->reply($id, 'agent-1', 'We are looking into it', isAgent: true);
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertCount(1, $ticket['replies']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$ticket['replies'][0]['is_agent']);
    }

    public function testReplyThrowsOnEmptyBody(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->expectException(\InvalidArgumentException::class);
        $this->st->reply($id, 'agent-1', '');
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function testAssignSetsAgent(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->assertTrue($this->st->assign($id, 'agent-1'));
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('agent-1', $ticket['assigned_to']);
    }

    // ── status transitions ────────────────────────────────────────────────────

    public function testPendingChangesStatusFromOpen(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->assertTrue($this->st->pending($id));
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $ticket['status']);
    }

    public function testCloseChangesStatusFromOpen(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->assertTrue($this->st->close($id));
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('closed', $ticket['status']);
    }

    public function testCloseChangesStatusFromPending(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->st->pending($id);
        $this->assertTrue($this->st->close($id));
    }

    public function testCloseReturnsFalseIfAlreadyClosed(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->st->close($id);
        $this->assertFalse($this->st->close($id));
    }

    public function testReopenChangesStatusFromClosed(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->st->close($id);
        $this->assertTrue($this->st->reopen($id));
        $ticket = $this->st->find($id);
        $this->assertNotNull($ticket);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('open', $ticket['status']);
    }

    public function testReopenReturnsFalseIfAlreadyOpen(): void
    {
        $id = $this->st->open('user-1', 'Subject');
        $this->assertFalse($this->st->reopen($id));
    }

    // ── listByStatus ──────────────────────────────────────────────────────────

    public function testListByStatusFiltersCorrectly(): void
    {
        $this->st->open('user-1', 'A');
        $id2 = $this->st->open('user-1', 'B');
        $this->st->close($id2);
        $this->assertCount(1, $this->st->listByStatus('open'));
        $this->assertCount(1, $this->st->listByStatus('closed'));
    }

    // ── listForUser ───────────────────────────────────────────────────────────

    public function testListForUserFiltersCorrectly(): void
    {
        $this->st->open('user-1', 'A');
        $this->st->open('user-2', 'B');
        $this->assertCount(1, $this->st->listForUser('user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAll(): void
    {
        $this->st->open('user-1', 'A');
        $this->st->open('user-1', 'B');
        $this->assertSame(2, $this->st->count());
    }

    public function testCountByStatus(): void
    {
        $id = $this->st->open('user-1', 'A');
        $this->st->open('user-1', 'B');
        $this->st->close($id);
        $this->assertSame(1, $this->st->count('open'));
        $this->assertSame(1, $this->st->count('closed'));
    }
}
