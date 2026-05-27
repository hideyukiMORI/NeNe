<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\UserFeedback;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserFeedbackTest extends TestCase
{
    private PDO $pdo;
    private UserFeedback $uf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_feedback (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                context     VARCHAR(255) NOT NULL,
                score       TINYINT      NOT NULL,
                comment     TEXT         NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->uf = new UserFeedback($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->uf->submit('user-1', 'checkout', 8);
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresFields(): void
    {
        $id  = $this->uf->submit('user-1', 'checkout', 9, 'Great!');
        $row = $this->uf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('checkout', $row['context']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(9, (int)$row['score']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Great!', $row['comment']);
    }

    public function testSubmitAllowsNullComment(): void
    {
        $id  = $this->uf->submit('user-1', 'checkout', 5);
        $row = $this->uf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['comment']);
    }

    public function testSubmitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uf->submit('', 'checkout', 5);
    }

    public function testSubmitThrowsOnEmptyContext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uf->submit('user-1', '', 5);
    }

    public function testSubmitThrowsOnNegativeScore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uf->submit('user-1', 'checkout', -1);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->uf->get(9999));
    }

    // ── averageScore ──────────────────────────────────────────────────────────

    public function testAverageScoreCalculates(): void
    {
        $this->uf->submit('user-1', 'checkout', 8);
        $this->uf->submit('user-2', 'checkout', 6);
        $avg = $this->uf->averageScore('checkout');
        $this->assertNotNull($avg);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertEqualsWithDelta(7.0, $avg, 0.001);
    }

    public function testAverageScoreNullWhenNoRows(): void
    {
        $this->assertNull($this->uf->averageScore('nonexistent'));
    }

    // ── countByScore ──────────────────────────────────────────────────────────

    public function testCountByScoreDistribution(): void
    {
        $this->uf->submit('user-1', 'checkout', 8);
        $this->uf->submit('user-2', 'checkout', 8);
        $this->uf->submit('user-3', 'checkout', 10);
        $dist = $this->uf->countByScore('checkout');
        $this->assertSame(2, $dist[8]);
        $this->assertSame(1, $dist[10]);
    }

    public function testCountByScoreEmptyForUnknownContext(): void
    {
        $this->assertSame([], $this->uf->countByScore('unknown'));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllSubmissions(): void
    {
        $this->uf->submit('user-1', 'checkout', 8);
        $this->uf->submit('user-1', 'search', 6);
        $this->uf->submit('user-2', 'checkout', 9);
        $rows = $this->uf->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    public function testForUserReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->uf->forUser('unknown'));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsLimitedRows(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->uf->submit("user-{$i}", 'checkout', $i);
        }

        $rows = $this->uf->recent('checkout', 3);
        $this->assertCount(3, $rows);
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $this->uf->submit('user-1', 'checkout', 3);
        $this->uf->submit('user-2', 'checkout', 9);
        $rows = $this->uf->recent('checkout');
        $this->assertSame(9, (int)$rows[0]['score']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesRow(): void
    {
        $id = $this->uf->submit('user-1', 'checkout', 7);
        $this->assertTrue($this->uf->remove($id));
        $this->assertNull($this->uf->get($id));
    }

    public function testRemoveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->uf->remove(9999));
    }

    // ── clearContext ──────────────────────────────────────────────────────────

    public function testClearContextDeletesAllForContext(): void
    {
        $this->uf->submit('user-1', 'checkout', 8);
        $this->uf->submit('user-2', 'checkout', 6);
        $this->uf->submit('user-1', 'search', 9);
        $deleted = $this->uf->clearContext('checkout');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->uf->recent('checkout'));
        $this->assertCount(1, $this->uf->recent('search'));
    }
}
