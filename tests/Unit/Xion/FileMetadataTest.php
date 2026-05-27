<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FileMetadata;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileMetadata using an in-memory SQLite database.
 */
final class FileMetadataTest extends TestCase
{
    private PDO $db;
    private FileMetadata $fm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE file_metadata (
                id           INTEGER      PRIMARY KEY AUTOINCREMENT,
                owner_id     VARCHAR(255) NOT NULL,
                path         VARCHAR(1024) NOT NULL,
                filename     VARCHAR(255) NOT NULL,
                mime_type    VARCHAR(127) NOT NULL,
                size_bytes   INTEGER      NOT NULL DEFAULT 0,
                storage      VARCHAR(64)  NOT NULL DEFAULT \'local\',
                deleted_at   DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->fm = new FileMetadata($this->db);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterReturnsId(): void
    {
        $id = $this->fm->register('user:1', '/uploads/a.jpg', 'photo.jpg', 'image/jpeg', 1024);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testRegisterStoresMetadata(): void
    {
        $id   = $this->fm->register('user:1', '/uploads/a.jpg', 'photo.jpg', 'image/jpeg', 2048, 's3');
        $file = $this->fm->find($id);
        $this->assertNotNull($file);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('/uploads/a.jpg', $file['path']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('photo.jpg', $file['filename']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('image/jpeg', $file['mime_type']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(2048, $file['size_bytes']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('s3', $file['storage']);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->fm->find(9999));
    }

    public function testFindReturnsRowForKnownId(): void
    {
        $id = $this->fm->register('user:1', '/uploads/b.pdf', 'doc.pdf', 'application/pdf');
        $this->assertNotNull($this->fm->find($id));
    }

    public function testFindReturnsSoftDeletedFile(): void
    {
        $id = $this->fm->register('user:1', '/uploads/c.jpg', 'img.jpg', 'image/jpeg');
        $this->fm->delete($id, 'user:1');
        $file = $this->fm->find($id);
        $this->assertNotNull($file);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertNotNull($file['deleted_at']);
    }

    // ── findByOwner ───────────────────────────────────────────────────────────

    public function testFindByOwnerReturnsActiveFiles(): void
    {
        $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->fm->register('user:1', '/uploads/b.jpg', 'b.jpg', 'image/jpeg');
        $this->assertCount(2, $this->fm->findByOwner('user:1'));
    }

    public function testFindByOwnerExcludesSoftDeleted(): void
    {
        $id = $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->fm->delete($id, 'user:1');
        $this->assertEmpty($this->fm->findByOwner('user:1'));
    }

    public function testFindByOwnerIsOwnerIsolated(): void
    {
        $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->assertEmpty($this->fm->findByOwner('user:2'));
    }

    public function testFindByOwnerFiltersByMimeType(): void
    {
        $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->fm->register('user:1', '/uploads/b.pdf', 'b.pdf', 'application/pdf');
        $images = $this->fm->findByOwner('user:1', 'image/');
        $this->assertCount(1, $images);
        $this->assertSame('image/jpeg', $images[0]['mime_type']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteReturnsTrueByOwner(): void
    {
        $id = $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->assertTrue($this->fm->delete($id, 'user:1'));
    }

    public function testDeleteReturnsFalseByWrongOwner(): void
    {
        $id = $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->assertFalse($this->fm->delete($id, 'user:2'));
    }

    public function testDeleteSetsDeletedAt(): void
    {
        $id   = $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->fm->delete($id, 'user:1');
        $file = $this->fm->find($id);
        $this->assertNotNull($file);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertNotNull($file['deleted_at']);
    }

    public function testDeleteAlreadyDeletedReturnsFalse(): void
    {
        $id = $this->fm->register('user:1', '/uploads/a.jpg', 'a.jpg', 'image/jpeg');
        $this->fm->delete($id, 'user:1');
        $this->assertFalse($this->fm->delete($id, 'user:1'));
    }
}
