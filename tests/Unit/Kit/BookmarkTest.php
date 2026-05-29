<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Bookmark;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Bookmark using an in-memory SQLite database.
 */
final class BookmarkTest extends TestCase
{
    private PDO $db;
    private Bookmark $bm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE bookmarks (
                id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                entity_type VARCHAR(64)  NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                collection  VARCHAR(64)  NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, entity_type, entity_id)
            )'
        );
        $this->bm = new Bookmark($this->db);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveReturnsTrueOnFirstBookmark(): void
    {
        $this->assertTrue($this->bm->save('user:1', 'article', '42'));
    }

    public function testSaveReturnsFalseWhenAlreadySaved(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->assertFalse($this->bm->save('user:1', 'article', '42'));
    }

    public function testSaveWithCollection(): void
    {
        $this->bm->save('user:1', 'article', '42', 'read-later');
        $list = $this->bm->list('user:1');
        $this->assertSame('read-later', $list[0]['collection']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveReturnsTrueWhenBookmarked(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->assertTrue($this->bm->remove('user:1', 'article', '42'));
    }

    public function testRemoveReturnsFalseWhenNotBookmarked(): void
    {
        $this->assertFalse($this->bm->remove('user:1', 'article', '42'));
    }

    public function testRemoveDeletesBookmark(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->bm->remove('user:1', 'article', '42');
        $this->assertFalse($this->bm->isSaved('user:1', 'article', '42'));
    }

    // ── isSaved ───────────────────────────────────────────────────────────────

    public function testIsSavedReturnsTrueAfterSave(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->assertTrue($this->bm->isSaved('user:1', 'article', '42'));
    }

    public function testIsSavedReturnsFalseBeforeSave(): void
    {
        $this->assertFalse($this->bm->isSaved('user:1', 'article', '42'));
    }

    public function testIsSavedIsUserIsolated(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->assertFalse($this->bm->isSaved('user:2', 'article', '42'));
    }

    public function testIsSavedIsEntityTypeIsolated(): void
    {
        $this->bm->save('user:1', 'article', '42');
        $this->assertFalse($this->bm->isSaved('user:1', 'product', '42'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllBookmarksForUser(): void
    {
        $this->bm->save('user:1', 'article', '1');
        $this->bm->save('user:1', 'article', '2');
        $this->assertCount(2, $this->bm->list('user:1'));
    }

    public function testListFiltersByEntityType(): void
    {
        $this->bm->save('user:1', 'article', '1');
        $this->bm->save('user:1', 'product', '99');
        $this->assertCount(1, $this->bm->list('user:1', 'article'));
    }

    public function testListFiltersByCollection(): void
    {
        $this->bm->save('user:1', 'article', '1', 'read-later');
        $this->bm->save('user:1', 'article', '2', 'favourites');
        $list = $this->bm->list('user:1', 'article', 'read-later');
        $this->assertCount(1, $list);
        $this->assertSame('1', $list[0]['entity_id']);
    }

    public function testListIsUserIsolated(): void
    {
        $this->bm->save('user:1', 'article', '1');
        $this->assertEmpty($this->bm->list('user:2'));
    }

    public function testListReturnsEmptyForNewUser(): void
    {
        $this->assertEmpty($this->bm->list('user:1'));
    }

    public function testListResultContainsExpectedKeys(): void
    {
        $this->bm->save('user:1', 'article', '42', 'fav');
        $list = $this->bm->list('user:1');
        $this->assertArrayHasKey('id', $list[0]);
        $this->assertArrayHasKey('entity_type', $list[0]);
        $this->assertArrayHasKey('entity_id', $list[0]);
        $this->assertArrayHasKey('collection', $list[0]);
        $this->assertArrayHasKey('created_at', $list[0]);
    }
}
