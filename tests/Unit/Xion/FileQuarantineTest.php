<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FileQuarantine;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileQuarantine.
 */
final class FileQuarantineTest extends TestCase
{
    private PDO $db;
    private FileQuarantine $fq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE file_quarantine (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id        VARCHAR(255) NOT NULL UNIQUE,
                owner_id       VARCHAR(255) NOT NULL DEFAULT \'\',
                reason         VARCHAR(100) NOT NULL DEFAULT \'\',
                notes          TEXT         NOT NULL DEFAULT \'\',
                status         VARCHAR(20)  NOT NULL DEFAULT \'quarantined\',
                reviewed_by    VARCHAR(255) NOT NULL DEFAULT \'\',
                review_notes   TEXT         NOT NULL DEFAULT \'\',
                quarantined_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at    DATETIME     DEFAULT NULL
            )
        ');
        $this->fq = new FileQuarantine($this->db);
    }

    // ── quarantine ────────────────────────────────────────────────────────────

    public function testQuarantineReturnsId(): void
    {
        $id = $this->fq->quarantine('file-1', 'user-1', 'av_flag', 'Malware detected');
        $this->assertGreaterThan(0, $id);
    }

    public function testQuarantineSetsStatusToQuarantined(): void
    {
        $this->fq->quarantine('file-1');
        $this->assertSame('quarantined', $this->fq->status('file-1'));
    }

    public function testQuarantineIsIdempotentUpsert(): void
    {
        $this->fq->quarantine('file-1', 'user-1', 'av_flag');
        $this->fq->quarantine('file-1', 'user-1', 'policy_violation'); // re-quarantine
        $this->assertSame('quarantined', $this->fq->status('file-1'));
        $row = $this->fq->find('file-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('policy_violation', $row['reason']);
    }

    public function testQuarantineResetsReleasedFile(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->release('file-1', 'admin-1');
        $this->fq->quarantine('file-1', 'user-1', 'second_scan');
        $this->assertSame('quarantined', $this->fq->status('file-1'));
    }

    public function testQuarantineThrowsOnEmptyFileId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fq->quarantine('');
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseSetsStatusToReleased(): void
    {
        $this->fq->quarantine('file-1');
        $this->assertTrue($this->fq->release('file-1', 'admin-1'));
        $this->assertSame('released', $this->fq->status('file-1'));
    }

    public function testReleaseStoresReviewedBy(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->release('file-1', 'admin-1', 'looks clean');
        $row = $this->fq->find('file-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('admin-1', $row['reviewed_by']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('looks clean', $row['review_notes']);
    }

    public function testReleaseReturnsFalseIfNotQuarantined(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->release('file-1', 'admin-1');
        $this->assertFalse($this->fq->release('file-1', 'admin-1')); // already released
    }

    public function testReleaseThrowsOnEmptyFileId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fq->release('', 'admin-1');
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function testRejectSetsStatusToRejected(): void
    {
        $this->fq->quarantine('file-1');
        $this->assertTrue($this->fq->reject('file-1', 'admin-1'));
        $this->assertSame('rejected', $this->fq->status('file-1'));
    }

    public function testRejectReturnsFalseIfNotQuarantined(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->reject('file-1', 'admin-1');
        $this->assertFalse($this->fq->reject('file-1', 'admin-1')); // already rejected
    }

    public function testRejectThrowsOnEmptyFileId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fq->reject('', 'admin-1');
    }

    // ── status / isQuarantined ────────────────────────────────────────────────

    public function testStatusReturnsNullForUnknownFile(): void
    {
        $this->assertNull($this->fq->status('nonexistent'));
    }

    public function testIsQuarantinedTrueWhenQuarantined(): void
    {
        $this->fq->quarantine('file-1');
        $this->assertTrue($this->fq->isQuarantined('file-1'));
    }

    public function testIsQuarantinedFalseAfterRelease(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->release('file-1', 'admin-1');
        $this->assertFalse($this->fq->isQuarantined('file-1'));
    }

    // ── listQuarantined ───────────────────────────────────────────────────────

    public function testListQuarantinedReturnsActiveOnly(): void
    {
        $this->fq->quarantine('file-1');
        $this->fq->quarantine('file-2');
        $this->fq->release('file-1', 'admin-1');
        $list = $this->fq->listQuarantined();
        $this->assertCount(1, $list);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('file-2', $list[0]['file_id']);
    }

    public function testListQuarantinedReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->fq->listQuarantined());
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesRecord(): void
    {
        $this->fq->quarantine('file-1');
        $this->assertTrue($this->fq->remove('file-1'));
        $this->assertNull($this->fq->find('file-1'));
    }

    public function testRemoveReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->fq->remove('nonexistent'));
    }
}
