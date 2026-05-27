<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ContentVersion;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentVersion.
 */
final class ContentVersionTest extends TestCase
{
    private PDO $db;
    private ContentVersion $cv;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE content_versions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                version     INTEGER      NOT NULL,
                body        TEXT         NOT NULL DEFAULT \'\',
                author_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, version)
            )
        ');
        $this->cv = new ContentVersion($this->db);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveReturnsVersionNumber(): void
    {
        $v = $this->cv->save('post', '1', 'Hello');
        $this->assertSame(1, $v);
    }

    public function testSaveIncrementsVersion(): void
    {
        $v1 = $this->cv->save('post', '1', 'v1');
        $v2 = $this->cv->save('post', '1', 'v2');
        $v3 = $this->cv->save('post', '1', 'v3');
        $this->assertSame(1, $v1);
        $this->assertSame(2, $v2);
        $this->assertSame(3, $v3);
    }

    public function testSaveIsolatesEntities(): void
    {
        $v1 = $this->cv->save('post', '1', 'Post 1 v1');
        $v2 = $this->cv->save('post', '2', 'Post 2 v1');
        $this->assertSame(1, $v1);
        $this->assertSame(1, $v2);
    }

    public function testSaveThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cv->save('', '1', 'body');
    }

    public function testSaveThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cv->save('post', '', 'body');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetLatestVersion(): void
    {
        $this->cv->save('post', '1', 'v1');
        $this->cv->save('post', '1', 'v2');
        $row = $this->cv->get('post', '1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['version']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('v2', $row['body']);
    }

    public function testGetSpecificVersion(): void
    {
        $this->cv->save('post', '1', 'v1');
        $this->cv->save('post', '1', 'v2');
        $row = $this->cv->get('post', '1', 1);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('v1', $row['body']);
    }

    public function testGetReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->cv->get('post', '999'));
    }

    public function testGetReturnsNullForMissingVersion(): void
    {
        $this->cv->save('post', '1', 'v1');
        $this->assertNull($this->cv->get('post', '1', 99));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryOldestFirst(): void
    {
        $this->cv->save('post', '1', 'v1', 'user-a');
        $this->cv->save('post', '1', 'v2', 'user-b');
        $history = $this->cv->history('post', '1');
        $this->assertCount(2, $history);
        $this->assertSame('v1', $history[0]['body']);
        $this->assertSame('v2', $history[1]['body']);
    }

    public function testHistoryReturnsEmptyForUnknownEntity(): void
    {
        $this->assertSame([], $this->cv->history('post', '999'));
    }

    // ── rollback ──────────────────────────────────────────────────────────────

    public function testRollbackCreatesNewVersion(): void
    {
        $this->cv->save('post', '1', 'original');
        $this->cv->save('post', '1', 'changed');
        $newVer = $this->cv->rollback('post', '1', 1);
        $this->assertSame(3, $newVer);
        $row = $this->cv->get('post', '1');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('original', $row['body']);
    }

    public function testRollbackReturnsZeroForMissingVersion(): void
    {
        $this->cv->save('post', '1', 'v1');
        $this->assertSame(0, $this->cv->rollback('post', '1', 99));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->cv->count('post', '1'));
        $this->cv->save('post', '1', 'v1');
        $this->cv->save('post', '1', 'v2');
        $this->assertSame(2, $this->cv->count('post', '1'));
    }

    // ── purgeOlderVersions ────────────────────────────────────────────────────

    public function testPurgeOlderVersionsKeepsLatest(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->cv->save('post', '1', "v{$i}");
        }
        $deleted = $this->cv->purgeOlderVersions('post', '1', 3);
        $this->assertSame(2, $deleted);
        $this->assertSame(3, $this->cv->count('post', '1'));
        $row = $this->cv->get('post', '1');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('v5', $row['body']);
    }

    public function testPurgeOlderVersionsReturnsZeroWhenBelowThreshold(): void
    {
        $this->cv->save('post', '1', 'v1');
        $this->cv->save('post', '1', 'v2');
        $this->assertSame(0, $this->cv->purgeOlderVersions('post', '1', 5));
    }
}
