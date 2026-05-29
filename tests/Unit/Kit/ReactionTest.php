<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Reaction;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReactionTest extends TestCase
{
    private PDO $pdo;
    private Reaction $r;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE reactions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                reaction    VARCHAR(64)  NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, user_id, reaction)
            )
        ');
        $this->r = new Reaction($this->pdo);
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function testToggleAddsReaction(): void
    {
        $added = $this->r->toggle('post', '1', 'user-1', '👍');
        $this->assertTrue($added);
        $this->assertTrue($this->r->has('post', '1', 'user-1', '👍'));
    }

    public function testToggleRemovesExistingReaction(): void
    {
        $this->r->toggle('post', '1', 'user-1', '👍');
        $added = $this->r->toggle('post', '1', 'user-1', '👍');
        $this->assertFalse($added);
        $this->assertFalse($this->r->has('post', '1', 'user-1', '👍'));
    }

    public function testToggleDifferentReactionsCoexist(): void
    {
        $this->r->toggle('post', '1', 'user-1', '👍');
        $this->r->toggle('post', '1', 'user-1', '❤️');
        $this->assertTrue($this->r->has('post', '1', 'user-1', '👍'));
        $this->assertTrue($this->r->has('post', '1', 'user-1', '❤️'));
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddIsIdempotent(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '1', 'user-1', '👍');
        $counts = $this->r->counts('post', '1');
        $this->assertSame(1, $counts['👍']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesReaction(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $result = $this->r->remove('post', '1', 'user-1', '👍');
        $this->assertTrue($result);
        $this->assertFalse($this->r->has('post', '1', 'user-1', '👍'));
    }

    public function testRemoveReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->r->remove('post', '1', 'user-1', '👍'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->r->has('post', '1', 'user-1', '👍'));
    }

    // ── counts ────────────────────────────────────────────────────────────────

    public function testCountsGroupsByReaction(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '1', 'user-2', '👍');
        $this->r->add('post', '1', 'user-3', '❤️');

        $counts = $this->r->counts('post', '1');
        $this->assertSame(2, $counts['👍']);
        $this->assertSame(1, $counts['❤️']);
    }

    public function testCountsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->r->counts('post', '99'));
    }

    public function testCountsIsolatedByEntity(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '2', 'user-1', '👍');

        $counts1 = $this->r->counts('post', '1');
        $counts2 = $this->r->counts('post', '2');
        $this->assertSame(1, $counts1['👍']);
        $this->assertSame(1, $counts2['👍']);
    }

    // ── byUser ────────────────────────────────────────────────────────────────

    public function testByUserReturnsUserReactions(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '1', 'user-1', '❤️');
        $this->r->add('post', '1', 'user-2', '👍');

        $mine = $this->r->byUser('post', '1', 'user-1');
        sort($mine);
        $this->assertCount(2, $mine);
        $this->assertContains('👍', $mine);
        $this->assertContains('❤️', $mine);
    }

    public function testByUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->r->byUser('post', '1', 'user-99'));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsAllRows(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '1', 'user-2', '❤️');

        $rows = $this->r->forEntity('post', '1');
        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('user_id', $rows[0]);
        $this->assertArrayHasKey('reaction', $rows[0]);
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->r->forEntity('post', '99'));
    }

    // ── clearEntity ───────────────────────────────────────────────────────────

    public function testClearEntityDeletesAllForEntity(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '1', 'user-2', '👍');
        $this->r->add('post', '2', 'user-1', '👍');

        $deleted = $this->r->clearEntity('post', '1');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->r->counts('post', '1'));
        $this->assertNotEmpty($this->r->counts('post', '2'));
    }

    public function testClearEntityReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->r->clearEntity('post', '99'));
    }

    // ── clearUser ─────────────────────────────────────────────────────────────

    public function testClearUserDeletesAllForUser(): void
    {
        $this->r->add('post', '1', 'user-1', '👍');
        $this->r->add('post', '2', 'user-1', '❤️');
        $this->r->add('post', '1', 'user-2', '👍');

        $deleted = $this->r->clearUser('user-1');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->r->byUser('post', '1', 'user-1'));
        $this->assertNotEmpty($this->r->byUser('post', '1', 'user-2'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testToggleThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->toggle('', '1', 'user-1', '👍');
    }

    public function testToggleThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->toggle('post', '', 'user-1', '👍');
    }

    public function testToggleThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->toggle('post', '1', '', '👍');
    }

    public function testToggleThrowsOnEmptyReaction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->toggle('post', '1', 'user-1', '');
    }

    public function testByUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->byUser('post', '1', '');
    }

    public function testClearUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->clearUser('');
    }
}
