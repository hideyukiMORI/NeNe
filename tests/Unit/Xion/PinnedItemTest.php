<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PinnedItem;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PinnedItem.
 */
final class PinnedItemTest extends TestCase
{
    private PDO $db;
    private PinnedItem $p;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE pinned_items (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                context    VARCHAR(150) NOT NULL,
                item       VARCHAR(190) NOT NULL,
                position   INTEGER      NOT NULL DEFAULT 0,
                pinned_by  BIGINT       NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (context, item)
            )
        ');
        $this->p = new PinnedItem($this->db);
    }

    public function testPinAppendsInOrder(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $this->p->pin('c', 'd');
        $this->assertSame(['a', 'b', 'd'], $this->p->items('c'));
    }

    public function testPinIsIdempotent(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'a');
        $this->assertSame(1, $this->p->count('c'));
    }

    public function testIsPinned(): void
    {
        $this->p->pin('c', 'a');
        $this->assertTrue($this->p->isPinned('c', 'a'));
        $this->assertFalse($this->p->isPinned('c', 'z'));
    }

    public function testUnpin(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $this->p->unpin('c', 'a');
        $this->assertSame(['b'], $this->p->items('c'));
    }

    public function testUnpinMissingIsNoop(): void
    {
        $this->p->unpin('c', 'ghost');
        $this->assertSame(0, $this->p->count('c'));
    }

    public function testMoveToTop(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $this->p->pin('c', 'd');
        $this->p->moveToTop('c', 'd');
        $this->assertSame(['d', 'a', 'b'], $this->p->items('c'));
    }

    public function testMoveToBottom(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $this->p->pin('c', 'd');
        $this->p->moveToBottom('c', 'a');
        $this->assertSame(['b', 'd', 'a'], $this->p->items('c'));
    }

    public function testIdempotentPinKeepsPosition(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $this->p->moveToTop('c', 'b');
        $this->p->pin('c', 'b'); // re-pin should not move it back
        $this->assertSame(['b', 'a'], $this->p->items('c'));
    }

    public function testContextsAreSeparate(): void
    {
        $this->p->pin('c1', 'a');
        $this->p->pin('c2', 'b');
        $this->assertSame(['a'], $this->p->items('c1'));
        $this->assertSame(['b'], $this->p->items('c2'));
    }

    public function testClear(): void
    {
        $this->p->pin('c', 'a');
        $this->p->pin('c', 'b');
        $removed = $this->p->clear('c');
        $this->assertSame(2, $removed);
        $this->assertSame([], $this->p->items('c'));
    }

    public function testPinRejectsEmptyContext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->pin('  ', 'a');
    }

    public function testPinRejectsEmptyItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->pin('c', '  ');
    }
}
