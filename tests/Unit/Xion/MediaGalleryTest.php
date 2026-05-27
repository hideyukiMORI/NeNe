<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\MediaGallery;
use PDO;
use PHPUnit\Framework\TestCase;

final class MediaGalleryTest extends TestCase
{
    private PDO $pdo;
    private MediaGallery $gallery;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE gallery_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                storage_key  TEXT         NOT NULL,
                caption      TEXT         NULL,
                position     INTEGER      NOT NULL DEFAULT 0,
                is_cover     TINYINT(1)   NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->gallery = new MediaGallery($this->pdo);
    }

    // ── addItem ───────────────────────────────────────────────────────────────

    public function testAddItemReturnsId(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddItemStoresCorrectly(): void
    {
        $id    = $this->gallery->addItem('post', '1', 's3://a.jpg', 'My caption', 3);
        $items = $this->gallery->items('post', '1');
        $this->assertCount(1, $items);
        $this->assertSame($id, (int)$items[0]['id']);
        $this->assertSame('s3://a.jpg', $items[0]['storage_key']);
        $this->assertSame('My caption', $items[0]['caption']);
        $this->assertSame(3, (int)$items[0]['position']);
        $this->assertSame(0, (int)$items[0]['is_cover']);
    }

    public function testAddItemThrowsOnEmptyStorageKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gallery->addItem('post', '1', '');
    }

    public function testAddItemThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gallery->addItem('', '1', 's3://a.jpg');
    }

    public function testAddItemThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gallery->addItem('post', '', 's3://a.jpg');
    }

    // ── removeItem ────────────────────────────────────────────────────────────

    public function testRemoveItemDeletesRow(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->assertTrue($this->gallery->removeItem($id));
        $this->assertCount(0, $this->gallery->items('post', '1'));
    }

    public function testRemoveItemReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->gallery->removeItem(9999));
    }

    // ── setCover / getCover ───────────────────────────────────────────────────

    public function testSetCoverMarksCoverItem(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->assertTrue($this->gallery->setCover($id));

        $items = $this->gallery->items('post', '1');
        $this->assertSame(1, (int)$items[0]['is_cover']);
    }

    public function testSetCoverClearsPreviousCover(): void
    {
        $id1 = $this->gallery->addItem('post', '1', 's3://a.jpg', null, 1);
        $id2 = $this->gallery->addItem('post', '1', 's3://b.jpg', null, 2);

        $this->gallery->setCover($id1);
        $this->gallery->setCover($id2);

        $items = $this->gallery->items('post', '1');
        $this->assertSame(0, (int)$items[0]['is_cover']); // id1 cleared
        $this->assertSame(1, (int)$items[1]['is_cover']); // id2 set
    }

    public function testGetCoverReturnsCoverItem(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->gallery->setCover($id);

        $cover = $this->gallery->getCover('post', '1');
        $this->assertNotNull($cover);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$cover['id']);
    }

    public function testGetCoverReturnsNullWhenNone(): void
    {
        $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->assertNull($this->gallery->getCover('post', '1'));
    }

    public function testSetCoverReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->gallery->setCover(9999));
    }

    // ── updateCaption ─────────────────────────────────────────────────────────

    public function testUpdateCaptionChangesCaption(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg', 'Old');
        $this->assertTrue($this->gallery->updateCaption($id, 'New'));

        $items = $this->gallery->items('post', '1');
        $this->assertSame('New', $items[0]['caption']);
    }

    public function testUpdateCaptionToNull(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg', 'Old');
        $this->gallery->updateCaption($id, null);

        $items = $this->gallery->items('post', '1');
        $this->assertNull($items[0]['caption']);
    }

    public function testUpdateCaptionReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->gallery->updateCaption(9999, 'x'));
    }

    // ── reorder ───────────────────────────────────────────────────────────────

    public function testReorderChangesPosition(): void
    {
        $id = $this->gallery->addItem('post', '1', 's3://a.jpg', null, 1);
        $this->assertTrue($this->gallery->reorder($id, 5));

        $items = $this->gallery->items('post', '1');
        $this->assertSame(5, (int)$items[0]['position']);
    }

    public function testReorderReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->gallery->reorder(9999, 1));
    }

    // ── items ─────────────────────────────────────────────────────────────────

    public function testItemsOrdersByPositionThenId(): void
    {
        $id3 = $this->gallery->addItem('post', '1', 's3://c.jpg', null, 3);
        $id1 = $this->gallery->addItem('post', '1', 's3://a.jpg', null, 1);
        $id2 = $this->gallery->addItem('post', '1', 's3://b.jpg', null, 2);

        $items = $this->gallery->items('post', '1');
        $this->assertSame($id1, (int)$items[0]['id']);
        $this->assertSame($id2, (int)$items[1]['id']);
        $this->assertSame($id3, (int)$items[2]['id']);
    }

    public function testItemsIsolatedByEntity(): void
    {
        $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->gallery->addItem('post', '2', 's3://b.jpg');

        $this->assertCount(1, $this->gallery->items('post', '1'));
        $this->assertCount(1, $this->gallery->items('post', '2'));
    }

    public function testItemsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->gallery->items('post', '99'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllForEntity(): void
    {
        $this->gallery->addItem('post', '1', 's3://a.jpg');
        $this->gallery->addItem('post', '1', 's3://b.jpg');
        $this->gallery->addItem('post', '2', 's3://c.jpg');

        $this->assertSame(2, $this->gallery->clear('post', '1'));
        $this->assertCount(0, $this->gallery->items('post', '1'));
        $this->assertCount(1, $this->gallery->items('post', '2'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectNumber(): void
    {
        $this->gallery->addItem('post', '5', 's3://a.jpg');
        $this->gallery->addItem('post', '5', 's3://b.jpg');
        $this->assertSame(2, $this->gallery->count('post', '5'));
    }

    public function testCountReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->gallery->count('post', '99'));
    }
}
