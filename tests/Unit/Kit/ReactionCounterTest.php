<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ReactionCounter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReactionCounter.
 */
final class ReactionCounterTest extends TestCase
{
    private PDO $db;
    private ReactionCounter $rc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE reactions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                reaction    VARCHAR(50)  NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, user_id)
            )
        ');
        $this->rc = new ReactionCounter($this->db);
    }

    // ── react — add ───────────────────────────────────────────────────────────

    public function testReactAddsNewReaction(): void
    {
        $result = $this->rc->react('post', '1', 'user-1', 'like');
        $this->assertSame('added', $result);
        $this->assertSame('like', $this->rc->userReaction('post', '1', 'user-1'));
    }

    public function testReactTogglesOffSameReaction(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $result = $this->rc->react('post', '1', 'user-1', 'like');
        $this->assertSame('removed', $result);
        $this->assertNull($this->rc->userReaction('post', '1', 'user-1'));
    }

    public function testReactSwitchesToDifferentReaction(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $result = $this->rc->react('post', '1', 'user-1', 'heart');
        $this->assertSame('switched', $result);
        $this->assertSame('heart', $this->rc->userReaction('post', '1', 'user-1'));
    }

    public function testReactThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rc->react('', '1', 'user-1', 'like');
    }

    public function testReactThrowsOnEmptyReaction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rc->react('post', '1', 'user-1', '');
    }

    // ── unreact ───────────────────────────────────────────────────────────────

    public function testUnreactRemovesReaction(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->assertTrue($this->rc->unreact('post', '1', 'user-1'));
        $this->assertNull($this->rc->userReaction('post', '1', 'user-1'));
    }

    public function testUnreactReturnsFalseIfNoReaction(): void
    {
        $this->assertFalse($this->rc->unreact('post', '1', 'user-1'));
    }

    // ── userReaction ──────────────────────────────────────────────────────────

    public function testUserReactionReturnsNullIfNoneSet(): void
    {
        $this->assertNull($this->rc->userReaction('post', '1', 'user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectNumberForType(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '1', 'user-2', 'like');
        $this->rc->react('post', '1', 'user-3', 'heart');
        $this->assertSame(2, $this->rc->count('post', '1', 'like'));
        $this->assertSame(1, $this->rc->count('post', '1', 'heart'));
    }

    public function testCountReturnsZeroForNoReactions(): void
    {
        $this->assertSame(0, $this->rc->count('post', '99', 'like'));
    }

    // ── counts ────────────────────────────────────────────────────────────────

    public function testCountsReturnsAllTypes(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '1', 'user-2', 'like');
        $this->rc->react('post', '1', 'user-3', 'heart');
        $counts = $this->rc->counts('post', '1');
        $this->assertSame(['like' => 2, 'heart' => 1], $counts);
    }

    public function testCountsReturnsEmptyForNoReactions(): void
    {
        $this->assertSame([], $this->rc->counts('post', '99'));
    }

    public function testCountsIsEntityScoped(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '2', 'user-1', 'heart');
        $this->assertSame(['like' => 1], $this->rc->counts('post', '1'));
        $this->assertSame(['heart' => 1], $this->rc->counts('post', '2'));
    }

    // ── reactors ─────────────────────────────────────────────────────────────

    public function testReactorsReturnsUserIds(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '1', 'user-2', 'like');
        $this->assertSame(['user-1', 'user-2'], $this->rc->reactors('post', '1', 'like'));
    }

    public function testReactorsReturnsEmptyForNoReaction(): void
    {
        $this->assertSame([], $this->rc->reactors('post', '1', 'like'));
    }

    // ── total ─────────────────────────────────────────────────────────────────

    public function testTotalCountsAllTypes(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '1', 'user-2', 'heart');
        $this->rc->react('post', '1', 'user-3', 'laugh');
        $this->assertSame(3, $this->rc->total('post', '1'));
    }

    public function testTotalReturnsZeroForNoReactions(): void
    {
        $this->assertSame(0, $this->rc->total('post', '99'));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeRemovesAllReactions(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '1', 'user-2', 'heart');
        $this->assertSame(2, $this->rc->purge('post', '1'));
        $this->assertSame(0, $this->rc->total('post', '1'));
    }

    public function testPurgeDoesNotAffectOtherEntities(): void
    {
        $this->rc->react('post', '1', 'user-1', 'like');
        $this->rc->react('post', '2', 'user-1', 'like');
        $this->rc->purge('post', '1');
        $this->assertSame(1, $this->rc->total('post', '2'));
    }
}
