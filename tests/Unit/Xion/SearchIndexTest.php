<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SearchIndex;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchIndex.
 */
final class SearchIndexTest extends TestCase
{
    private PDO $db;
    private SearchIndex $si;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE search_index (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                token       VARCHAR(100) NOT NULL,
                frequency   INT          NOT NULL DEFAULT 1,
                indexed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, token)
            )
        ');
        $this->si = new SearchIndex($this->db);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexStoresTokens(): void
    {
        $this->si->index('post', '1', 'PHP arrays are great');
        $results = $this->si->search('php');
        $this->assertCount(1, $results);
        $this->assertSame('post', $results[0]['entity_type']);
    }

    public function testIndexIsIdempotent(): void
    {
        $this->si->index('post', '1', 'PHP arrays');
        $this->si->index('post', '1', 'PHP functions'); // re-index
        $results = $this->si->search('functions');
        $this->assertCount(1, $results);
        // Old tokens removed
        $noArray = $this->si->search('arrays');
        $this->assertCount(0, $noArray);
    }

    public function testIndexThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->si->index('', '1', 'text');
    }

    public function testIndexThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->si->index('post', '', 'text');
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function testSearchReturnsMatchingEntities(): void
    {
        $this->si->index('post', '1', 'PHP arrays');
        $this->si->index('post', '2', 'Python lists');
        $results = $this->si->search('php');
        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['entity_id']);
    }

    public function testSearchIsAndLogic(): void
    {
        $this->si->index('post', '1', 'PHP arrays are great');
        $this->si->index('post', '2', 'PHP is cool');
        $results = $this->si->search('php arrays');
        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['entity_id']);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $this->si->index('post', '1', 'PHP Arrays');
        $results = $this->si->search('PHP arrays');
        $this->assertCount(1, $results);
    }

    public function testSearchRanksByFrequency(): void
    {
        $this->si->index('post', '1', 'php php php'); // freq 3
        $this->si->index('post', '2', 'php');         // freq 1
        $results = $this->si->search('php');
        $this->assertSame('1', $results[0]['entity_id']);
        $this->assertGreaterThan((int)$results[1]['score'], (int)$results[0]['score']);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $this->si->index('post', '1', 'hello world');
        $this->assertSame([], $this->si->search('nonexistent'));
    }

    public function testSearchFiltersbyEntityType(): void
    {
        $this->si->index('post', '1', 'php');
        $this->si->index('page', '1', 'php');
        $results = $this->si->search('php', 10, 'post');
        $this->assertCount(1, $results);
        $this->assertSame('post', $results[0]['entity_type']);
    }

    public function testSearchReturnsEmptyForBlankQuery(): void
    {
        $this->si->index('post', '1', 'hello');
        $this->assertSame([], $this->si->search(''));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesFromIndex(): void
    {
        $this->si->index('post', '1', 'php arrays');
        $this->assertTrue($this->si->remove('post', '1'));
        $this->assertSame([], $this->si->search('php'));
    }

    public function testRemoveReturnsFalseIfNotIndexed(): void
    {
        $this->assertFalse($this->si->remove('post', '999'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->si->count());
    }

    public function testCountByEntityType(): void
    {
        $this->si->index('post', '1', 'hello');
        $this->si->index('post', '2', 'world');
        $this->si->index('page', '1', 'hi');
        $this->assertSame(2, $this->si->count('post'));
        $this->assertSame(1, $this->si->count('page'));
    }
}
