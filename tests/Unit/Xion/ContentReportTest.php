<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ContentReport;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContentReportTest extends TestCase
{
    private PDO $pdo;
    private ContentReport $cr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE content_reports (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id  VARCHAR(255) NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                content_id   VARCHAR(255) NOT NULL,
                reason       VARCHAR(100) NOT NULL,
                note         TEXT         NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                reviewer_id  VARCHAR(255) NULL,
                reviewed_at  DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cr = new ContentReport($this->pdo);
    }

    // ── report ────────────────────────────────────────────────────────────────

    public function testReportReturnsId(): void
    {
        $id = $this->cr->report('user-1', 'post', '42', 'spam');
        $this->assertGreaterThan(0, $id);
    }

    public function testReportStoresFields(): void
    {
        $id  = $this->cr->report('user-1', 'post', '42', 'spam', 'Repeated post');
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['reporter_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('post', $row['content_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('42', $row['content_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('spam', $row['reason']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Repeated post', $row['note']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContentReport::STATUS_PENDING, $row['status']);
    }

    public function testReportThrowsOnEmptyReporterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->report('', 'post', '1', 'spam');
    }

    public function testReportThrowsOnEmptyContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->report('user-1', '', '1', 'spam');
    }

    public function testReportThrowsOnEmptyContentId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->report('user-1', 'post', '', 'spam');
    }

    public function testReportThrowsOnEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->report('user-1', 'post', '1', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->cr->get(9999));
    }

    // ── action ────────────────────────────────────────────────────────────────

    public function testActionChangesStatusToActioned(): void
    {
        $id  = $this->cr->report('user-1', 'post', '1', 'spam');
        $this->assertTrue($this->cr->action($id, 'mod-1'));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContentReport::STATUS_ACTIONED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('mod-1', $row['reviewer_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['reviewed_at']);
    }

    public function testActionReturnsFalseIfNotPending(): void
    {
        $id = $this->cr->report('user-1', 'post', '1', 'spam');
        $this->cr->action($id, 'mod-1');
        $this->assertFalse($this->cr->action($id, 'mod-2')); // already actioned
    }

    public function testActionReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cr->action(9999, 'mod-1'));
    }

    // ── dismiss ───────────────────────────────────────────────────────────────

    public function testDismissChangesStatusToDismissed(): void
    {
        $id  = $this->cr->report('user-1', 'post', '1', 'offensive');
        $this->assertTrue($this->cr->dismiss($id, 'mod-1'));
        $row = $this->cr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContentReport::STATUS_DISMISSED, $row['status']);
    }

    public function testDismissReturnsFalseIfNotPending(): void
    {
        $id = $this->cr->report('user-1', 'post', '1', 'offensive');
        $this->cr->dismiss($id, 'mod-1');
        $this->assertFalse($this->cr->dismiss($id, 'mod-2'));
    }

    // ── byStatus ──────────────────────────────────────────────────────────────

    public function testByStatusFiltersPending(): void
    {
        $id1 = $this->cr->report('user-1', 'post', '1', 'spam');
        $this->cr->report('user-2', 'post', '2', 'hate');
        $this->cr->action($id1, 'mod-1');
        $pending = $this->cr->byStatus(ContentReport::STATUS_PENDING);
        $this->assertCount(1, $pending);
    }

    public function testByStatusReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->cr->byStatus(ContentReport::STATUS_ACTIONED));
    }

    // ── forContent ────────────────────────────────────────────────────────────

    public function testForContentReturnsMatchingReports(): void
    {
        $this->cr->report('user-1', 'post', '42', 'spam');
        $this->cr->report('user-2', 'post', '42', 'hate');
        $this->cr->report('user-3', 'post', '99', 'spam');
        $rows = $this->cr->forContent('post', '42');
        $this->assertCount(2, $rows);
    }

    public function testForContentReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->cr->forContent('post', '999'));
    }

    // ── countByReason ─────────────────────────────────────────────────────────

    public function testCountByReasonDistribution(): void
    {
        $this->cr->report('user-1', 'post', '1', 'spam');
        $this->cr->report('user-2', 'post', '2', 'spam');
        $this->cr->report('user-3', 'post', '3', 'hate');
        $dist = $this->cr->countByReason();
        $this->assertSame(2, $dist['spam']);
        $this->assertSame(1, $dist['hate']);
    }

    public function testCountByReasonEmptyWhenNoReports(): void
    {
        $this->assertSame([], $this->cr->countByReason());
    }
}
