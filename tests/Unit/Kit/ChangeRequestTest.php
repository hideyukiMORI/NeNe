<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ChangeRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class ChangeRequestTest extends TestCase
{
    private PDO $pdo;
    private ChangeRequest $cr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE change_requests (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                proposer_id     VARCHAR(255) NOT NULL,
                title           TEXT         NOT NULL,
                description     TEXT         NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT \'draft\',
                reviewer_id     VARCHAR(255) NULL,
                reviewer_note   TEXT         NULL,
                decided_at      DATETIME     NULL,
                implemented_at  DATETIME     NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cr = new ChangeRequest($this->pdo);
    }

    // ── propose ───────────────────────────────────────────────────────────────

    public function testProposeReturnsId(): void
    {
        $id = $this->cr->propose('user-1', 'Increase pool size');
        $this->assertGreaterThan(0, $id);
    }

    public function testProposeStoresFields(): void
    {
        $id  = $this->cr->propose('user-1', 'Increase pool size', 'From 10 to 50.');
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['proposer_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Increase pool size', $row['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_DRAFT, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('From 10 to 50.', $row['description']);
    }

    public function testProposeThrowsOnEmptyProposerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->propose('', 'Title');
    }

    public function testProposeThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->propose('user-1', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->cr->get(9999));
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitChangesDraftToSubmitted(): void
    {
        $id  = $this->cr->propose('user-1', 'Title');
        $this->assertTrue($this->cr->submit($id));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_SUBMITTED, $row['status']);
    }

    public function testSubmitReturnsFalseIfNotDraft(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->assertFalse($this->cr->submit($id)); // already submitted
    }

    public function testSubmitReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cr->submit(9999));
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function testApproveChangesStatus(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->assertTrue($this->cr->approve($id, 'reviewer-1', 'LGTM'));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_APPROVED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('reviewer-1', $row['reviewer_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('LGTM', $row['reviewer_note']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['decided_at']);
    }

    public function testApproveReturnsFalseIfNotSubmitted(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        // draft, not submitted
        $this->assertFalse($this->cr->approve($id, 'reviewer-1'));
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function testRejectChangesStatus(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->assertTrue($this->cr->reject($id, 'reviewer-1', 'Too risky'));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_REJECTED, $row['status']);
    }

    public function testRejectReturnsFalseIfNotSubmitted(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->assertFalse($this->cr->reject($id, 'reviewer-1'));
    }

    // ── implement ─────────────────────────────────────────────────────────────

    public function testImplementChangesStatus(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->cr->approve($id, 'reviewer-1');
        $this->assertTrue($this->cr->implement($id));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_IMPLEMENTED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['implemented_at']);
    }

    public function testImplementReturnsFalseIfNotApproved(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->assertFalse($this->cr->implement($id)); // submitted, not approved
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseChangesStatus(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->cr->submit($id);
        $this->cr->approve($id, 'reviewer-1');
        $this->cr->implement($id);
        $this->assertTrue($this->cr->close($id));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ChangeRequest::STATUS_CLOSED, $row['status']);
    }

    public function testCloseReturnsFalseIfNotImplemented(): void
    {
        $id = $this->cr->propose('user-1', 'Title');
        $this->assertFalse($this->cr->close($id));
    }

    // ── byStatus ──────────────────────────────────────────────────────────────

    public function testByStatusFilters(): void
    {
        $id1 = $this->cr->propose('user-1', 'CR1');
        $this->cr->submit($id1);
        $this->cr->propose('user-2', 'CR2'); // stays draft
        $this->assertCount(1, $this->cr->byStatus(ChangeRequest::STATUS_SUBMITTED));
        $this->assertCount(1, $this->cr->byStatus(ChangeRequest::STATUS_DRAFT));
    }

    public function testByStatusReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->cr->byStatus(ChangeRequest::STATUS_CLOSED));
    }

    // ── byProposer ────────────────────────────────────────────────────────────

    public function testByProposerFilters(): void
    {
        $this->cr->propose('user-1', 'CR1');
        $this->cr->propose('user-1', 'CR2');
        $this->cr->propose('user-2', 'CR3');
        $this->assertCount(2, $this->cr->byProposer('user-1'));
        $this->assertCount(1, $this->cr->byProposer('user-2'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldTerminalRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO change_requests (proposer_id, title, status, created_at)
             VALUES ('user-1', 'Old', 'closed', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO change_requests (proposer_id, title, status, created_at)
             VALUES ('user-2', 'Old2', 'rejected', '2020-01-02 00:00:00')"
        );
        $deleted = $this->cr->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteDraft(): void
    {
        $id = $this->cr->propose('user-1', 'Draft CR');
        $this->pdo->exec("UPDATE change_requests SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->cr->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
