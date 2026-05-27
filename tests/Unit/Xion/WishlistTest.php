<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Wishlist;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Wishlist.
 */
final class WishlistTest extends TestCase
{
    private PDO $db;
    private Wishlist $wl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE wishlists (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                note        TEXT         NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, entity_type, entity_id)
            )
        ');
        $this->wl = new Wishlist($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddInsertsItem(): void
    {
        $this->wl->add('user-1', 'product', '42');
        $this->assertTrue($this->wl->contains('user-1', 'product', '42'));
    }

    public function testAddIsIdempotent(): void
    {
        $this->wl->add('user-1', 'product', '42');
        $this->wl->add('user-1', 'product', '42'); // should not throw
        $this->assertSame(1, $this->wl->count('user-1'));
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->add('user-1', '', '42');
    }

    public function testAddThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->add('user-1', 'product', '');
    }

    public function testAddStoresNote(): void
    {
        $this->wl->add('user-1', 'product', '42', 'Birthday present');
        $items = $this->wl->list('user-1');
        $this->assertSame('Birthday present', $items[0]['note']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesItem(): void
    {
        $this->wl->add('user-1', 'product', '42');
        $this->assertTrue($this->wl->remove('user-1', 'product', '42'));
        $this->assertFalse($this->wl->contains('user-1', 'product', '42'));
    }

    public function testRemoveReturnsFalseForMissingItem(): void
    {
        $this->assertFalse($this->wl->remove('user-1', 'product', '999'));
    }

    public function testRemoveDoesNotCrossUserBoundary(): void
    {
        $this->wl->add('user-1', 'product', '42');
        $this->assertFalse($this->wl->remove('user-2', 'product', '42'));
        $this->assertTrue($this->wl->contains('user-1', 'product', '42'));
    }

    // ── contains ──────────────────────────────────────────────────────────────

    public function testContainsReturnsFalseForMissingItem(): void
    {
        $this->assertFalse($this->wl->contains('user-1', 'product', '999'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllItems(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-1', 'article', '2');
        $this->assertCount(2, $this->wl->list('user-1'));
    }

    public function testListFilterByEntityType(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-1', 'article', '2');
        $products = $this->wl->list('user-1', entityType: 'product');
        $this->assertCount(1, $products);
        $this->assertSame('product', $products[0]['entity_type']);
    }

    public function testListIsUserScoped(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-2', 'product', '1');
        $this->assertCount(1, $this->wl->list('user-1'));
        $this->assertCount(1, $this->wl->list('user-2'));
    }

    public function testListReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->wl->list('nobody'));
    }

    // ── updateNote ────────────────────────────────────────────────────────────

    public function testUpdateNoteChangesNote(): void
    {
        $this->wl->add('user-1', 'product', '42', 'Old note');
        $this->assertTrue($this->wl->updateNote('user-1', 'product', '42', 'New note'));
        $items = $this->wl->list('user-1');
        $this->assertSame('New note', $items[0]['note']);
    }

    public function testUpdateNoteReturnsFalseForMissingItem(): void
    {
        $this->assertFalse($this->wl->updateNote('user-1', 'product', '999', 'x'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForEmptyWishlist(): void
    {
        $this->assertSame(0, $this->wl->count('user-1'));
    }

    public function testCountReturnsCorrectNumber(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-1', 'product', '2');
        $this->assertSame(2, $this->wl->count('user-1'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearRemovesAllItems(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-1', 'product', '2');
        $this->assertSame(2, $this->wl->clear('user-1'));
        $this->assertSame(0, $this->wl->count('user-1'));
    }

    public function testClearDoesNotAffectOtherUsers(): void
    {
        $this->wl->add('user-1', 'product', '1');
        $this->wl->add('user-2', 'product', '1');
        $this->wl->clear('user-1');
        $this->assertSame(1, $this->wl->count('user-2'));
    }
}
