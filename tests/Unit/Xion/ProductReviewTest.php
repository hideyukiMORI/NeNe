<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ProductReview;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProductReviewTest extends TestCase
{
    private PDO $pdo;
    private ProductReview $r;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE reviews (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                rating      TINYINT(1)   NOT NULL,
                body        TEXT         NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                helpful     INTEGER      NOT NULL DEFAULT 0,
                not_helpful INTEGER      NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, user_id)
            )
        ');
        $this->r = new ProductReview($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5, 'Great!');
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresCorrectly(): void
    {
        $id  = $this->r->submit('product', '1', 'u1', 4, 'Good');
        $row = $this->r->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(4, (int)$row['rating']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Good', $row['body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $row['status']);
    }

    public function testSubmitThrowsOnInvalidRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->submit('product', '1', 'u1', 6);
    }

    public function testSubmitThrowsOnRatingZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->submit('product', '1', 'u1', 0);
    }

    public function testSubmitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->submit('product', '1', '', 5);
    }

    public function testSubmitThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->submit('', '1', 'u1', 5);
    }

    // ── approve / reject ──────────────────────────────────────────────────────

    public function testApproveSetsStatusApproved(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5);
        $this->assertTrue($this->r->approve($id));

        $row = $this->r->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('approved', $row['status']);
    }

    public function testRejectSetsStatusRejected(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5);
        $this->assertTrue($this->r->reject($id));

        $row = $this->r->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('rejected', $row['status']);
    }

    public function testApproveReturnsFalseForAlreadyApproved(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5);
        $this->r->approve($id);
        $this->assertFalse($this->r->approve($id)); // already approved, not pending
    }

    public function testApproveReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->r->approve(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesReview(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 3);
        $this->assertTrue($this->r->delete($id));
        $this->assertNull($this->r->find($id));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->r->delete(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsOnlyApproved(): void
    {
        $id1 = $this->r->submit('product', '1', 'u1', 5);
        $id2 = $this->r->submit('product', '1', 'u2', 4);
        $this->r->approve($id1);
        // id2 remains pending

        $rows = $this->r->forEntity('product', '1');
        $this->assertCount(1, $rows);
        $this->assertSame($id1, (int)$rows[0]['id']);
    }

    public function testForEntityWithApprovedOnlyFalseReturnsAll(): void
    {
        $id1 = $this->r->submit('product', '1', 'u1', 5);
        $id2 = $this->r->submit('product', '1', 'u2', 4);
        $this->r->approve($id1);

        $rows = $this->r->forEntity('product', '1', false);
        $this->assertCount(2, $rows);
    }

    public function testForEntityIsolatedByEntity(): void
    {
        $id1 = $this->r->submit('product', '1', 'u1', 5);
        $id2 = $this->r->submit('product', '2', 'u1', 3);
        $this->r->approve($id1);
        $this->r->approve($id2);

        $this->assertCount(1, $this->r->forEntity('product', '1'));
        $this->assertCount(1, $this->r->forEntity('product', '2'));
    }

    // ── byUser ────────────────────────────────────────────────────────────────

    public function testByUserReturnsUsersReviews(): void
    {
        $this->r->submit('product', '1', 'u1', 5);
        $this->r->submit('product', '2', 'u1', 4);
        $this->r->submit('product', '3', 'u2', 3);

        $rows = $this->r->byUser('u1');
        $this->assertCount(2, $rows);
    }

    // ── averageRating ─────────────────────────────────────────────────────────

    public function testAverageRatingCalculatesCorrectly(): void
    {
        $id1 = $this->r->submit('product', '1', 'u1', 4);
        $id2 = $this->r->submit('product', '1', 'u2', 2);
        $this->r->approve($id1);
        $this->r->approve($id2);

        $this->assertEqualsWithDelta(3.0, $this->r->averageRating('product', '1'), 0.01);
    }

    public function testAverageRatingReturnsZeroWhenNoApproved(): void
    {
        $this->r->submit('product', '1', 'u1', 5); // pending, not approved
        $this->assertSame(0.0, $this->r->averageRating('product', '1'));
    }

    public function testAverageRatingReturnsZeroWhenNoReviews(): void
    {
        $this->assertSame(0.0, $this->r->averageRating('product', '99'));
    }

    // ── ratingBreakdown ───────────────────────────────────────────────────────

    public function testRatingBreakdownCountsCorrectly(): void
    {
        $id1 = $this->r->submit('product', '1', 'u1', 5);
        $id2 = $this->r->submit('product', '1', 'u2', 5);
        $id3 = $this->r->submit('product', '1', 'u3', 3);
        $this->r->approve($id1);
        $this->r->approve($id2);
        $this->r->approve($id3);

        $breakdown = $this->r->ratingBreakdown('product', '1');
        $this->assertSame(2, $breakdown[5]);
        $this->assertSame(1, $breakdown[3]);
        $this->assertSame(0, $breakdown[1]);
        $this->assertSame(0, $breakdown[2]);
        $this->assertSame(0, $breakdown[4]);
    }

    // ── voteHelpful / voteNotHelpful ──────────────────────────────────────────

    public function testVoteHelpfulIncrementsCounter(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5);
        $this->r->voteHelpful($id);
        $this->r->voteHelpful($id);

        $row = $this->r->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['helpful']);
    }

    public function testVoteNotHelpfulIncrementsCounter(): void
    {
        $id = $this->r->submit('product', '1', 'u1', 5);
        $this->r->voteNotHelpful($id);

        $row = $this->r->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['not_helpful']);
    }

    public function testVoteHelpfulReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->r->voteHelpful(9999));
    }
}
