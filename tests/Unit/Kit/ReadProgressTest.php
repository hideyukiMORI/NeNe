<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ReadProgress;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReadProgress.
 */
final class ReadProgressTest extends TestCase
{
    private PDO $db;
    private ReadProgress $rp;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE read_progress (
                user_id     VARCHAR(255) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                position    INTEGER      NOT NULL DEFAULT 0,
                total       INTEGER      NOT NULL DEFAULT 0,
                completed   TINYINT(1)   NOT NULL DEFAULT 0,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, entity_type, entity_id)
            )
        ');
        $this->rp = new ReadProgress($this->db);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveCreatesRecord(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 5, total: 10);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(5, $rec['position']);
    }

    public function testSaveIsUpsert(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 3, total: 10);
        $this->rp->save('user-1', 'article', '42', position: 7, total: 10);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(7, $rec['position']);
    }

    public function testSaveThrowsOnNegativePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rp->save('user-1', 'article', '42', position: -1);
    }

    public function testSaveThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rp->save('user-1', '', '42', position: 1);
    }

    public function testSaveAutoSetsCompletedWhenPositionReachesTotal(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 10, total: 10);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($rec['completed']);
    }

    public function testSaveCompletedFalseWhenPositionBelowTotal(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 5, total: 10);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertFalse($rec['completed']);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingRecord(): void
    {
        $this->assertNull($this->rp->get('user-1', 'article', '99'));
    }

    public function testGetReturnsPercent(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 25, total: 100);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertEqualsWithDelta(25.0, $rec['percent'], 0.01);
    }

    public function testGetReturnsZeroPercentWhenTotalIsZero(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 5, total: 0);
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(0.0, $rec['percent']);
    }

    // ── markCompleted ─────────────────────────────────────────────────────────

    public function testMarkCompletedSetsFlag(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 3, total: 10);
        $this->rp->markCompleted('user-1', 'article', '42');
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($rec['completed']);
    }

    public function testMarkCompletedCreatesRecordIfAbsent(): void
    {
        $this->rp->markCompleted('user-1', 'article', '42');
        $rec = $this->rp->get('user-1', 'article', '42');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($rec['completed']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRecord(): void
    {
        $this->rp->save('user-1', 'article', '42', position: 5);
        $this->assertTrue($this->rp->delete('user-1', 'article', '42'));
        $this->assertNull($this->rp->get('user-1', 'article', '42'));
    }

    public function testDeleteReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->rp->delete('user-1', 'article', '99'));
    }

    // ── listInProgress / listCompleted ────────────────────────────────────────

    public function testListInProgressExcludesCompleted(): void
    {
        $this->rp->save('user-1', 'article', '1', position: 5, total: 10);
        $this->rp->save('user-1', 'article', '2', position: 10, total: 10); // completed
        $list = $this->rp->listInProgress('user-1');
        $this->assertCount(1, $list);
        $this->assertSame('1', $list[0]['entity_id']);
    }

    public function testListCompletedIncludesOnlyCompleted(): void
    {
        $this->rp->save('user-1', 'article', '1', position: 5, total: 10);
        $this->rp->save('user-1', 'article', '2', position: 10, total: 10);
        $list = $this->rp->listCompleted('user-1');
        $this->assertCount(1, $list);
        $this->assertSame('2', $list[0]['entity_id']);
    }

    public function testListFilterByEntityType(): void
    {
        $this->rp->save('user-1', 'article', '1', position: 5, total: 10);
        $this->rp->save('user-1', 'video', '2', position: 3, total: 10);
        $list = $this->rp->listInProgress('user-1', entityType: 'article');
        $this->assertCount(1, $list);
        $this->assertSame('article', $list[0]['entity_type']);
    }
}
