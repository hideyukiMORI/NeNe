<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\MediaMetadata;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaMetadata.
 */
final class MediaMetadataTest extends TestCase
{
    private PDO $db;
    private MediaMetadata $mm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE media_files (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                file_key   VARCHAR(255) NOT NULL UNIQUE,
                mime_type  VARCHAR(100) NOT NULL DEFAULT \'\',
                file_size  BIGINT       NOT NULL DEFAULT 0,
                attributes TEXT         NOT NULL DEFAULT \'{}\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE media_meta (
                file_id    INTEGER      NOT NULL,
                meta_key   VARCHAR(100) NOT NULL,
                meta_value TEXT         NOT NULL DEFAULT \'\',
                PRIMARY KEY (file_id, meta_key)
            )
        ');
        $this->mm = new MediaMetadata($this->db);
    }

    // ── register + find ───────────────────────────────────────────────────────

    public function testRegisterReturnsId(): void
    {
        $id = $this->mm->register('file-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testRegisterStoresAttributes(): void
    {
        $id  = $this->mm->register('img-1', 'image/jpeg', 204800, ['width' => 1920, 'height' => 1080]);
        $row = $this->mm->find($id);
        $this->assertSame('image/jpeg', $row['mime_type']);
        $this->assertSame(204800, (int)$row['file_size']);
        $this->assertSame(['width' => 1920, 'height' => 1080], $row['attributes']);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->mm->find(999));
    }

    public function testRegisterThrowsOnEmptyFileKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mm->register('');
    }

    // ── findByKey ─────────────────────────────────────────────────────────────

    public function testFindByKeyReturnsFile(): void
    {
        $this->mm->register('uuid-abc', 'video/mp4');
        $row = $this->mm->findByKey('uuid-abc');
        $this->assertSame('video/mp4', $row['mime_type']);
    }

    public function testFindByKeyReturnsNullForUnknown(): void
    {
        $this->assertNull($this->mm->findByKey('no-such-key'));
    }

    // ── setMeta + getMeta ─────────────────────────────────────────────────────

    public function testSetMetaAndGetMeta(): void
    {
        $id = $this->mm->register('img-2');
        $this->mm->setMeta($id, 'alt_text', 'A mountain');
        $this->assertSame('A mountain', $this->mm->getMeta($id, 'alt_text'));
    }

    public function testGetMetaReturnsDefaultWhenNotSet(): void
    {
        $id = $this->mm->register('img-3');
        $this->assertSame('n/a', $this->mm->getMeta($id, 'caption', 'n/a'));
    }

    public function testSetMetaIsUpsert(): void
    {
        $id = $this->mm->register('img-4');
        $this->mm->setMeta($id, 'alt_text', 'original');
        $this->mm->setMeta($id, 'alt_text', 'updated');
        $this->assertSame('updated', $this->mm->getMeta($id, 'alt_text'));
    }

    public function testSetMetaThrowsOnEmptyKey(): void
    {
        $id = $this->mm->register('img-5');
        $this->expectException(\InvalidArgumentException::class);
        $this->mm->setMeta($id, '', 'value');
    }

    // ── allMeta ───────────────────────────────────────────────────────────────

    public function testAllMetaReturnsKeyValueMap(): void
    {
        $id = $this->mm->register('img-6');
        $this->mm->setMeta($id, 'alt_text', 'hello');
        $this->mm->setMeta($id, 'caption', 'world');
        $meta = $this->mm->allMeta($id);
        $this->assertSame(['alt_text' => 'hello', 'caption' => 'world'], $meta);
    }

    public function testAllMetaReturnsEmptyForNoMeta(): void
    {
        $id = $this->mm->register('img-7');
        $this->assertSame([], $this->mm->allMeta($id));
    }

    // ── deleteMeta ────────────────────────────────────────────────────────────

    public function testDeleteMetaRemovesKey(): void
    {
        $id = $this->mm->register('img-8');
        $this->mm->setMeta($id, 'alt_text', 'hello');
        $this->assertTrue($this->mm->deleteMeta($id, 'alt_text'));
        $this->assertSame('', $this->mm->getMeta($id, 'alt_text'));
    }

    public function testDeleteMetaReturnsFalseIfNotPresent(): void
    {
        $id = $this->mm->register('img-9');
        $this->assertFalse($this->mm->deleteMeta($id, 'missing'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesFileAndMeta(): void
    {
        $id = $this->mm->register('to-delete');
        $this->mm->setMeta($id, 'alt_text', 'bye');
        $this->assertTrue($this->mm->remove($id));
        $this->assertNull($this->mm->find($id));
        $this->assertSame([], $this->mm->allMeta($id));
    }

    public function testRemoveReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->mm->remove(999));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->mm->count());
    }

    public function testCountIncrementsOnRegister(): void
    {
        $this->mm->register('f1');
        $this->mm->register('f2');
        $this->assertSame(2, $this->mm->count());
    }

    public function testCountDecrementsOnRemove(): void
    {
        $id = $this->mm->register('f1');
        $this->mm->remove($id);
        $this->assertSame(0, $this->mm->count());
    }
}
