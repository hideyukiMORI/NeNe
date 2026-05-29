<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SearchHistory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchHistory using an in-memory SQLite database.
 */
final class SearchHistoryTest extends TestCase
{
    private PDO $db;
    private SearchHistory $sh;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE search_history (
                id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                query       VARCHAR(255) NOT NULL,
                searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, query)
            )'
        );
        $this->sh = new SearchHistory($this->db);
    }

    // ── push ──────────────────────────────────────────────────────────────────

    public function testPushAddsQuery(): void
    {
        $this->sh->push('user:1', 'php');
        $this->assertSame(['php'], $this->sh->recent('user:1'));
    }

    public function testPushDeduplicate(): void
    {
        $this->sh->push('user:1', 'php');
        $this->sh->push('user:1', 'php');
        $this->assertCount(1, $this->sh->recent('user:1'));
    }

    public function testPushDuplicateMoveToTop(): void
    {
        $this->sh->push('user:1', 'php');
        $this->sh->push('user:1', 'oop');
        $this->sh->push('user:1', 'php');
        $this->assertSame('php', $this->sh->recent('user:1')[0]);
    }

    public function testPushTrimsOlderThanMaxEntries(): void
    {
        $sh = new SearchHistory($this->db, maxEntries: 3);
        $sh->push('user:1', 'a');
        $sh->push('user:1', 'b');
        $sh->push('user:1', 'c');
        $sh->push('user:1', 'd');  // 'a' should be trimmed
        $recent = $sh->recent('user:1', 10);
        $this->assertCount(3, $recent);
        $this->assertNotContains('a', $recent);
    }

    public function testPushEmptyQueryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sh->push('user:1', '');
    }

    public function testPushWhitespaceOnlyQueryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sh->push('user:1', '   ');
    }

    public function testPushTrimsQueryWhitespace(): void
    {
        $this->sh->push('user:1', '  php  ');
        $this->assertSame(['php'], $this->sh->recent('user:1'));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsNewestFirst(): void
    {
        $this->sh->push('user:1', 'first');
        $this->sh->push('user:1', 'second');
        $recent = $this->sh->recent('user:1');
        $this->assertSame('second', $recent[0]);
        $this->assertSame('first', $recent[1]);
    }

    public function testRecentReturnsEmptyForNewUser(): void
    {
        $this->assertEmpty($this->sh->recent('user:1'));
    }

    public function testRecentIsUserIsolated(): void
    {
        $this->sh->push('user:1', 'php');
        $this->assertEmpty($this->sh->recent('user:2'));
    }

    public function testRecentLimitIsApplied(): void
    {
        $this->sh->push('user:1', 'a');
        $this->sh->push('user:1', 'b');
        $this->sh->push('user:1', 'c');
        $this->assertCount(2, $this->sh->recent('user:1', 2));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearRemovesAllEntries(): void
    {
        $this->sh->push('user:1', 'php');
        $this->sh->push('user:1', 'oop');
        $this->sh->clear('user:1');
        $this->assertEmpty($this->sh->recent('user:1'));
    }

    public function testClearReturnsCountOfRemovedEntries(): void
    {
        $this->sh->push('user:1', 'php');
        $this->sh->push('user:1', 'oop');
        $this->assertSame(2, $this->sh->clear('user:1'));
    }

    public function testClearIsUserIsolated(): void
    {
        $this->sh->push('user:1', 'php');
        $this->sh->push('user:2', 'oop');
        $this->sh->clear('user:1');
        $this->assertSame(['oop'], $this->sh->recent('user:2'));
    }
}
