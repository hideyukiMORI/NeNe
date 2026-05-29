<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\GdprRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class GdprRequestTest extends TestCase
{
    private PDO $pdo;
    private GdprRequest $gdpr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE gdpr_requests (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         VARCHAR(255) NOT NULL,
                type            VARCHAR(30)  NOT NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                notes           TEXT         NULL,
                response_notes  TEXT         NULL,
                submitted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at DATETIME     NULL,
                completed_at    DATETIME     NULL
            )
        ');
        $this->gdpr = new GdprRequest($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresCorrectly(): void
    {
        $id  = $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS, 'Need my data');
        $req = $this->gdpr->find($id);
        $this->assertNotNull($req);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $req['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GdprRequest::TYPE_ACCESS, $req['type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GdprRequest::STATUS_PENDING, $req['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Need my data', $req['notes']);
    }

    public function testSubmitAllTypes(): void
    {
        $types = [
            GdprRequest::TYPE_ACCESS,
            GdprRequest::TYPE_RECTIFICATION,
            GdprRequest::TYPE_ERASURE,
            GdprRequest::TYPE_PORTABILITY,
        ];
        foreach ($types as $type) {
            $id = $this->gdpr->submit('user-1', $type);
            $this->assertGreaterThan(0, $id);
        }
    }

    public function testSubmitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gdpr->submit('', GdprRequest::TYPE_ERASURE);
    }

    public function testSubmitThrowsOnInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gdpr->submit('user-1', 'invalid-type');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->gdpr->find(9999));
    }

    // ── acknowledge ───────────────────────────────────────────────────────────

    public function testAcknowledgeChangesPendingToAcknowledged(): void
    {
        $id     = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $result = $this->gdpr->acknowledge($id);
        $this->assertTrue($result);

        $req = $this->gdpr->find($id);
        $this->assertNotNull($req);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GdprRequest::STATUS_ACKNOWLEDGED, $req['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($req['acknowledged_at']);
    }

    public function testAcknowledgeReturnsFalseWhenAlreadyCompleted(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->complete($id);
        $this->assertFalse($this->gdpr->acknowledge($id));
    }

    public function testAcknowledgeReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->gdpr->acknowledge(9999));
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteFromPendingState(): void
    {
        $id     = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $result = $this->gdpr->complete($id, 'All data removed');
        $this->assertTrue($result);

        $req = $this->gdpr->find($id);
        $this->assertNotNull($req);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GdprRequest::STATUS_COMPLETED, $req['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('All data removed', $req['response_notes']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($req['completed_at']);
    }

    public function testCompleteFromAcknowledgedState(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->acknowledge($id);
        $this->assertTrue($this->gdpr->complete($id));
    }

    public function testCompleteReturnsFalseWhenAlreadyCompleted(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->complete($id);
        $this->assertFalse($this->gdpr->complete($id));
    }

    public function testCompleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->gdpr->complete(9999));
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function testRejectFromPendingState(): void
    {
        $id     = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $result = $this->gdpr->reject($id, 'Active contract prevents erasure');
        $this->assertTrue($result);

        $req = $this->gdpr->find($id);
        $this->assertNotNull($req);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GdprRequest::STATUS_REJECTED, $req['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Active contract prevents erasure', $req['response_notes']);
    }

    public function testRejectFromAcknowledgedState(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->acknowledge($id);
        $this->assertTrue($this->gdpr->reject($id));
    }

    public function testRejectReturnsFalseWhenAlreadyCompleted(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->complete($id);
        $this->assertFalse($this->gdpr->reject($id));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRequest(): void
    {
        $id = $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS);
        $this->assertTrue($this->gdpr->delete($id));
        $this->assertNull($this->gdpr->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->gdpr->delete(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllRequestsForUser(): void
    {
        $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS);
        $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->submit('user-2', GdprRequest::TYPE_PORTABILITY);

        $this->assertCount(2, $this->gdpr->forUser('user-1'));
        $this->assertCount(1, $this->gdpr->forUser('user-2'));
    }

    public function testForUserReturnsNewestFirst(): void
    {
        $id1 = $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS);
        $id2 = $this->gdpr->submit('user-1', GdprRequest::TYPE_ERASURE);
        $list = $this->gdpr->forUser('user-1');
        $this->assertSame($id2, (int)$list[0]['id']);
        $this->assertSame($id1, (int)$list[1]['id']);
    }

    public function testForUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->gdpr->forUser('nobody'));
    }

    // ── pending ───────────────────────────────────────────────────────────────

    public function testPendingReturnsPendingAndAcknowledged(): void
    {
        $id1 = $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS);
        $id2 = $this->gdpr->submit('user-2', GdprRequest::TYPE_ERASURE);
        $this->gdpr->acknowledge($id2);
        $id3 = $this->gdpr->submit('user-3', GdprRequest::TYPE_PORTABILITY);
        $this->gdpr->complete($id3);

        $open = $this->gdpr->pending();
        $this->assertCount(2, $open);
        $openIds = array_map('intval', array_column($open, 'id'));
        $this->assertContains($id1, $openIds);
        $this->assertContains($id2, $openIds);
    }

    public function testPendingOrdersOldestFirst(): void
    {
        $id1 = $this->gdpr->submit('user-1', GdprRequest::TYPE_ACCESS);
        $id2 = $this->gdpr->submit('user-2', GdprRequest::TYPE_ERASURE);
        $list = $this->gdpr->pending();
        $this->assertSame($id1, (int)$list[0]['id']);
        $this->assertSame($id2, (int)$list[1]['id']);
    }

    public function testPendingRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->gdpr->submit('user-' . $i, GdprRequest::TYPE_ERASURE);
        }
        $page1 = $this->gdpr->pending(3, 0);
        $page2 = $this->gdpr->pending(3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── countByType / countByStatus ───────────────────────────────────────────

    public function testCountByType(): void
    {
        $this->gdpr->submit('u1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->submit('u2', GdprRequest::TYPE_ERASURE);
        $this->gdpr->submit('u3', GdprRequest::TYPE_ACCESS);
        $this->assertSame(2, $this->gdpr->countByType(GdprRequest::TYPE_ERASURE));
        $this->assertSame(1, $this->gdpr->countByType(GdprRequest::TYPE_ACCESS));
        $this->assertSame(0, $this->gdpr->countByType(GdprRequest::TYPE_PORTABILITY));
    }

    public function testCountByStatus(): void
    {
        $id1 = $this->gdpr->submit('u1', GdprRequest::TYPE_ERASURE);
        $this->gdpr->submit('u2', GdprRequest::TYPE_ACCESS);
        $this->gdpr->complete($id1);
        $this->assertSame(1, $this->gdpr->countByStatus(GdprRequest::STATUS_PENDING));
        $this->assertSame(1, $this->gdpr->countByStatus(GdprRequest::STATUS_COMPLETED));
        $this->assertSame(0, $this->gdpr->countByStatus(GdprRequest::STATUS_REJECTED));
    }
}
