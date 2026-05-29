<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Approval;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Approval.
 */
final class ApprovalTest extends TestCase
{
    private PDO $db;
    private Approval $approval;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE approvals (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_type VARCHAR(100) NOT NULL,
                resource_id   VARCHAR(255) NOT NULL,
                requester_id  VARCHAR(255) NOT NULL,
                approver_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                status        VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                note          TEXT         NOT NULL DEFAULT \'\',
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at    DATETIME     NULL
            )
        ');
        $this->approval = new Approval($this->db);
    }

    // ── request ───────────────────────────────────────────────────────────────

    public function testRequestReturnsPositiveId(): void
    {
        $id = $this->approval->request('post', '42', 'author-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testRequestCreatesWithPendingStatus(): void
    {
        $id   = $this->approval->request('post', '42', 'author-1');
        $rec  = $this->approval->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(Approval::STATUS_PENDING, $rec['status']);
    }

    public function testRequestThrowsOnEmptyResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->approval->request('', '42', 'author-1');
    }

    public function testRequestThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->approval->request('post', '', 'author-1');
    }

    public function testRequestThrowsOnEmptyRequesterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->approval->request('post', '42', '');
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function testApproveChangesStatusToApproved(): void
    {
        $id = $this->approval->request('post', '42', 'author-1');
        $this->assertTrue($this->approval->approve($id, 'editor-1', 'LGTM'));
        $rec = $this->approval->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(Approval::STATUS_APPROVED, $rec['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('editor-1', $rec['approver_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('LGTM', $rec['note']);
    }

    public function testApproveReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->approval->approve(999, 'editor-1'));
    }

    public function testApproveSetsDecidedAt(): void
    {
        $id = $this->approval->request('post', '42', 'author-1');
        $this->approval->approve($id, 'editor-1');
        $rec = $this->approval->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($rec['decided_at']);
    }

    public function testApproveCannotOverrideAlreadyDecided(): void
    {
        $id = $this->approval->request('post', '42', 'author-1');
        $this->approval->reject($id, 'editor-1');
        $this->assertFalse($this->approval->approve($id, 'editor-2'));
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function testRejectChangesStatusToRejected(): void
    {
        $id = $this->approval->request('post', '42', 'author-1');
        $this->assertTrue($this->approval->reject($id, 'editor-1', 'Needs revision'));
        $rec = $this->approval->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(Approval::STATUS_REJECTED, $rec['status']);
    }

    public function testRejectReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->approval->reject(999, 'editor-1'));
    }

    // ── forResource ───────────────────────────────────────────────────────────

    public function testForResourceReturnsLatestApproval(): void
    {
        $this->approval->request('post', '42', 'author-1');
        $id2 = $this->approval->request('post', '42', 'author-1');
        $rec = $this->approval->forResource('post', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$rec['id']);
    }

    public function testForResourceReturnsNullWhenNone(): void
    {
        $this->assertNull($this->approval->forResource('post', '99'));
    }

    // ── pending ───────────────────────────────────────────────────────────────

    public function testPendingListsOnlyPending(): void
    {
        $id1 = $this->approval->request('post', '1', 'author-1');
        $id2 = $this->approval->request('post', '2', 'author-1');
        $this->approval->approve($id1, 'editor-1');
        $list = $this->approval->pending();
        $this->assertCount(1, $list);
        $this->assertSame($id2, (int)$list[0]['id']);
    }

    public function testPendingReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->approval->pending());
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllRecordsNewestFirst(): void
    {
        $id1 = $this->approval->request('post', '42', 'author-1');
        $id2 = $this->approval->request('post', '42', 'author-1');
        $history = $this->approval->history('post', '42');
        $this->assertCount(2, $history);
        $this->assertSame($id2, (int)$history[0]['id']);
    }

    public function testHistoryReturnsEmptyForUnknownResource(): void
    {
        $this->assertSame([], $this->approval->history('post', '99'));
    }

    // ── countPending ─────────────────────────────────────────────────────────

    public function testCountPendingReturnsCorrectCount(): void
    {
        $this->assertSame(0, $this->approval->countPending());
        $id = $this->approval->request('post', '42', 'author-1');
        $this->approval->request('post', '43', 'author-1');
        $this->assertSame(2, $this->approval->countPending());
        $this->approval->approve($id, 'editor-1');
        $this->assertSame(1, $this->approval->countPending());
    }
}
