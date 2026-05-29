<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DownloadCounter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DownloadCounter.
 */
final class DownloadCounterTest extends TestCase
{
    private PDO $db;
    private DownloadCounter $dc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE download_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type   VARCHAR(100) NOT NULL,
                entity_id     VARCHAR(255) NOT NULL,
                user_id       VARCHAR(255) NOT NULL DEFAULT \'\',
                ip_address    VARCHAR(45)  NOT NULL DEFAULT \'\',
                downloaded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dc = new DownloadCounter($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsIntId(): void
    {
        $id = $this->dc->record('file', '42');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dc->record('', '42');
    }

    public function testRecordThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dc->record('file', '');
    }

    public function testRecordMultipleTimes(): void
    {
        $this->dc->record('file', '42', 'user-1');
        $this->dc->record('file', '42', 'user-1');
        $this->assertSame(2, $this->dc->total('file', '42'));
    }

    // ── total ─────────────────────────────────────────────────────────────────

    public function testTotalReturnsZeroForNoDownloads(): void
    {
        $this->assertSame(0, $this->dc->total('file', '42'));
    }

    public function testTotalCountsCorrectly(): void
    {
        $this->dc->record('file', '42');
        $this->dc->record('file', '42');
        $this->dc->record('file', '99'); // different entity
        $this->assertSame(2, $this->dc->total('file', '42'));
    }

    // ── countByUser ───────────────────────────────────────────────────────────

    public function testCountByUserReturnsZeroIfNoDownloads(): void
    {
        $this->assertSame(0, $this->dc->countByUser('file', '42', 'user-1'));
    }

    public function testCountByUserCountsOnlyThatUser(): void
    {
        $this->dc->record('file', '42', 'user-1');
        $this->dc->record('file', '42', 'user-1');
        $this->dc->record('file', '42', 'user-2');
        $this->assertSame(2, $this->dc->countByUser('file', '42', 'user-1'));
        $this->assertSame(1, $this->dc->countByUser('file', '42', 'user-2'));
    }

    // ── hasReachedLimit ───────────────────────────────────────────────────────

    public function testHasReachedLimitReturnsFalseBeforeLimit(): void
    {
        $this->dc->record('file', '42', 'user-1');
        $this->assertFalse($this->dc->hasReachedLimit('file', '42', 'user-1', limit: 3));
    }

    public function testHasReachedLimitReturnsTrueAtLimit(): void
    {
        $this->dc->record('file', '42', 'user-1');
        $this->dc->record('file', '42', 'user-1');
        $this->dc->record('file', '42', 'user-1');
        $this->assertTrue($this->dc->hasReachedLimit('file', '42', 'user-1', limit: 3));
    }

    // ── historyByUser ─────────────────────────────────────────────────────────

    public function testHistoryByUserReturnsEventsNewestFirst(): void
    {
        $id1 = $this->dc->record('file', '1', 'user-1');
        $id2 = $this->dc->record('file', '2', 'user-1');
        $hist = $this->dc->historyByUser('user-1');
        $this->assertSame((string)$id2, (string)$hist[0]['id']);
    }

    public function testHistoryByUserRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->dc->record('file', (string)$i, 'user-1');
        }
        $this->assertCount(3, $this->dc->historyByUser('user-1', limit: 3));
    }

    public function testHistoryByUserFiltersByEntityType(): void
    {
        $this->dc->record('file', '1', 'user-1');
        $this->dc->record('report', '2', 'user-1');
        $this->assertCount(1, $this->dc->historyByUser('user-1', entityType: 'file'));
    }

    // ── topEntities ───────────────────────────────────────────────────────────

    public function testTopEntitiesReturnsRankedList(): void
    {
        $this->dc->record('file', 'a');
        $this->dc->record('file', 'a');
        $this->dc->record('file', 'b');

        $top = $this->dc->topEntities('file', limit: 5);
        $this->assertSame('a', $top[0]['entity_id']);
        $this->assertSame(2, $top[0]['downloads']);
    }

    public function testTopEntitiesRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->dc->record('file', (string)$i);
        }
        $this->assertCount(2, $this->dc->topEntities('file', limit: 2));
    }

    public function testTopEntitiesReturnsEmptyForNoData(): void
    {
        $this->assertSame([], $this->dc->topEntities('report'));
    }
}
