<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\BookmarkCollection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BookmarkCollection.
 */
final class BookmarkCollectionTest extends TestCase
{
    private PDO $db;
    private BookmarkCollection $bc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE bookmarks (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         VARCHAR(255) NOT NULL,
                collection_name VARCHAR(255) NOT NULL DEFAULT \'default\',
                entity_type     VARCHAR(100) NOT NULL,
                entity_id       VARCHAR(255) NOT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, entity_type, entity_id)
            )
        ');
        $this->bc = new BookmarkCollection($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddCreatesBookmark(): void
    {
        $this->bc->add('user-1', 'post', '42');
        $this->assertTrue($this->bc->isBookmarked('user-1', 'post', '42'));
    }

    public function testAddDefaultsToDefaultCollection(): void
    {
        $this->bc->add('user-1', 'post', '42');
        $list = $this->bc->list('user-1', 'default');
        $this->assertCount(1, $list);
    }

    public function testAddWithCustomCollection(): void
    {
        $this->bc->add('user-1', 'post', '42', 'Reading list');
        $list = $this->bc->list('user-1', 'Reading list');
        $this->assertCount(1, $list);
    }

    public function testAddIsIdempotentUpdatesCollection(): void
    {
        $this->bc->add('user-1', 'post', '42', 'A');
        $this->bc->add('user-1', 'post', '42', 'B'); // moves to B
        $this->assertCount(0, $this->bc->list('user-1', 'A'));
        $this->assertCount(1, $this->bc->list('user-1', 'B'));
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->add('', 'post', '42');
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->add('user-1', '', '42');
    }

    public function testAddThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->add('user-1', 'post', '');
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesBookmark(): void
    {
        $this->bc->add('user-1', 'post', '42');
        $this->assertTrue($this->bc->remove('user-1', 'post', '42'));
        $this->assertFalse($this->bc->isBookmarked('user-1', 'post', '42'));
    }

    public function testRemoveReturnsFalseIfNotBookmarked(): void
    {
        $this->assertFalse($this->bc->remove('user-1', 'post', '999'));
    }

    // ── move ──────────────────────────────────────────────────────────────────

    public function testMoveChangesCollection(): void
    {
        $this->bc->add('user-1', 'post', '42', 'A');
        $this->assertTrue($this->bc->move('user-1', 'post', '42', 'B'));
        $this->assertCount(1, $this->bc->list('user-1', 'B'));
        $this->assertCount(0, $this->bc->list('user-1', 'A'));
    }

    public function testMoveReturnsFalseIfNotBookmarked(): void
    {
        $this->assertFalse($this->bc->move('user-1', 'post', '999', 'B'));
    }

    // ── isBookmarked ──────────────────────────────────────────────────────────

    public function testIsBookmarkedFalseForNonBookmarked(): void
    {
        $this->assertFalse($this->bc->isBookmarked('user-1', 'post', '42'));
    }

    public function testIsBookmarkedIsUserScoped(): void
    {
        $this->bc->add('user-1', 'post', '42');
        $this->assertFalse($this->bc->isBookmarked('user-2', 'post', '42'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllBookmarks(): void
    {
        $this->bc->add('user-1', 'post', '1');
        $this->bc->add('user-1', 'post', '2', 'Saved');
        $this->assertCount(2, $this->bc->list('user-1'));
    }

    public function testListFilteredByCollection(): void
    {
        $this->bc->add('user-1', 'post', '1', 'A');
        $this->bc->add('user-1', 'post', '2', 'B');
        $this->assertCount(1, $this->bc->list('user-1', 'A'));
    }

    // ── collections ───────────────────────────────────────────────────────────

    public function testCollectionsReturnsNamesWithCounts(): void
    {
        $this->bc->add('user-1', 'post', '1', 'A');
        $this->bc->add('user-1', 'post', '2', 'A');
        $this->bc->add('user-1', 'post', '3', 'B');
        $cols = $this->bc->collections('user-1');
        $this->assertCount(2, $cols);
        $this->assertSame('A', $cols[0]['collection_name']);
        $this->assertSame(2, $cols[0]['count']);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAllBookmarks(): void
    {
        $this->bc->add('user-1', 'post', '1');
        $this->bc->add('user-1', 'post', '2');
        $this->assertSame(2, $this->bc->count('user-1'));
    }

    public function testCountByCollection(): void
    {
        $this->bc->add('user-1', 'post', '1', 'A');
        $this->bc->add('user-1', 'post', '2', 'B');
        $this->assertSame(1, $this->bc->count('user-1', 'A'));
    }

    // ── clearCollection ───────────────────────────────────────────────────────

    public function testClearCollectionDeletesAll(): void
    {
        $this->bc->add('user-1', 'post', '1', 'A');
        $this->bc->add('user-1', 'post', '2', 'A');
        $this->bc->add('user-1', 'post', '3', 'B');
        $this->assertSame(2, $this->bc->clearCollection('user-1', 'A'));
        $this->assertSame(1, $this->bc->count('user-1'));
    }
}
