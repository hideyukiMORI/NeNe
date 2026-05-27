<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FileChunk;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileChunk.
 */
final class FileChunkTest extends TestCase
{
    private PDO $db;
    private FileChunk $fc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE file_chunk_uploads (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                upload_id    VARCHAR(36)  NOT NULL UNIQUE,
                user_id      VARCHAR(255) NOT NULL DEFAULT \'\',
                filename     VARCHAR(500) NOT NULL DEFAULT \'\',
                total_size   BIGINT       NOT NULL DEFAULT 0,
                total_chunks INT          NOT NULL DEFAULT 1,
                received     INT          NOT NULL DEFAULT 0,
                status       VARCHAR(20)  NOT NULL DEFAULT \'uploading\',
                started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->fc = new FileChunk($this->db);
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartReturnsUploadId(): void
    {
        $id = $this->fc->start('user-1', 'doc.pdf', 1024, 3);
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    public function testStartCreatesRecord(): void
    {
        $id  = $this->fc->start('user-1', 'file.zip', 500000, 5);
        $row = $this->fc->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('file.zip', $row['filename']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(5, (int)$row['total_chunks']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('uploading', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['received']);
    }

    public function testStartThrowsOnZeroChunks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fc->start('user-1', 'file.zip', 100, 0);
    }

    public function testStartProducesUniqueIds(): void
    {
        $id1 = $this->fc->start();
        $id2 = $this->fc->start();
        $this->assertNotSame($id1, $id2);
    }

    // ── received ──────────────────────────────────────────────────────────────

    public function testReceivedIncrementsCounter(): void
    {
        $id = $this->fc->start('user-1', 'f.zip', 0, 3);
        $this->assertTrue($this->fc->received($id, 0));
        $this->assertTrue($this->fc->received($id, 1));
        $row = $this->fc->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['received']);
    }

    public function testReceivedReturnsFalseForUnknownUpload(): void
    {
        $this->assertFalse($this->fc->received('bad-id', 0));
    }

    public function testReceivedReturnsFalseAfterDone(): void
    {
        $id = $this->fc->start('user-1', 'f.zip', 0, 1);
        $this->fc->received($id, 0);
        $this->fc->markDone($id);
        $this->assertFalse($this->fc->received($id, 1));
    }

    // ── isComplete ────────────────────────────────────────────────────────────

    public function testIsCompleteReturnsTrueWhenAllChunksReceived(): void
    {
        $id = $this->fc->start('user-1', 'f.zip', 0, 2);
        $this->assertFalse($this->fc->isComplete($id));
        $this->fc->received($id, 0);
        $this->assertFalse($this->fc->isComplete($id));
        $this->fc->received($id, 1);
        $this->assertTrue($this->fc->isComplete($id));
    }

    public function testIsCompleteReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->fc->isComplete('unknown'));
    }

    // ── markDone / markFailed ─────────────────────────────────────────────────

    public function testMarkDoneSetsStatus(): void
    {
        $id = $this->fc->start();
        $this->assertTrue($this->fc->markDone($id));
        $row = $this->fc->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('done', $row['status']);
    }

    public function testMarkFailedSetsStatus(): void
    {
        $id = $this->fc->start();
        $this->assertTrue($this->fc->markFailed($id));
        $row = $this->fc->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $row['status']);
    }

    public function testMarkDoneReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->fc->markDone('unknown'));
    }

    // ── pendingForUser ────────────────────────────────────────────────────────

    public function testPendingForUserReturnsInProgressUploads(): void
    {
        $id1 = $this->fc->start('user-1', 'a.zip', 0, 3);
        $id2 = $this->fc->start('user-1', 'b.zip', 0, 2);
        $this->fc->start('user-2', 'c.zip', 0, 1);
        $this->fc->markDone($id2);

        $pending = $this->fc->pendingForUser('user-1');
        $this->assertCount(1, $pending);
        $this->assertSame($id1, $pending[0]['upload_id']);
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldUploads(): void
    {
        $id  = $this->fc->start();
        $old = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $this->db->exec("UPDATE file_chunk_uploads SET started_at = '{$old}' WHERE upload_id = '{$id}'");

        $this->fc->start(); // recent upload
        $deleted = $this->fc->purgeOlderThan(1);
        $this->assertSame(1, $deleted);
    }
}
