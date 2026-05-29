<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CacheEntry;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CacheEntry.
 */
final class CacheEntryTest extends TestCase
{
    private PDO $db;
    private CacheEntry $cache;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE cache_entries (
                cache_key   VARCHAR(255) NOT NULL PRIMARY KEY,
                cache_value TEXT         NOT NULL DEFAULT \'\',
                expires_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cache = new CacheEntry($this->db);
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function testGetReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->cache->get('missing'));
    }

    public function testSetIsUpsert(): void
    {
        $this->cache->set('key', 'a');
        $this->cache->set('key', 'b');
        $this->assertSame('b', $this->cache->get('key'));
        $this->assertSame(1, $this->cache->count());
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache->set('', 'value');
    }

    // ── TTL ───────────────────────────────────────────────────────────────────

    public function testSetWithTtlStoresEntry(): void
    {
        $this->cache->set('ttl-key', 'data', 60);
        $this->assertSame('data', $this->cache->get('ttl-key'));
    }

    public function testExpiredEntryReturnsNull(): void
    {
        // Manually insert an already-expired entry
        $this->db->exec(
            "INSERT INTO cache_entries (cache_key, cache_value, expires_at)
             VALUES ('expired', 'old', '2000-01-01 00:00:00')"
        );
        $this->assertNull($this->cache->get('expired'));
    }

    public function testNonExpiredEntryIsReturned(): void
    {
        $this->db->exec(
            "INSERT INTO cache_entries (cache_key, cache_value, expires_at)
             VALUES ('fresh', 'data', '2099-12-31 23:59:59')"
        );
        $this->assertSame('data', $this->cache->get('fresh'));
    }

    public function testNullTtlNeverExpires(): void
    {
        $this->cache->set('forever', 'permanent', null);
        $this->assertSame('permanent', $this->cache->get('forever'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->has('k'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('nope'));
    }

    public function testHasReturnsFalseForExpiredKey(): void
    {
        $this->db->exec(
            "INSERT INTO cache_entries (cache_key, cache_value, expires_at)
             VALUES ('ex', 'v', '2000-01-01 00:00:00')"
        );
        $this->assertFalse($this->cache->has('ex'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDelete(): void
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->delete('k'));
        $this->assertNull($this->cache->get('k'));
    }

    public function testDeleteReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->cache->delete('nope'));
    }

    // ── flush ─────────────────────────────────────────────────────────────────

    public function testFlushDeletesAll(): void
    {
        $this->cache->set('a', '1');
        $this->cache->set('b', '2');
        $deleted = $this->cache->flush();
        $this->assertSame(2, $deleted);
        $this->assertSame(0, $this->cache->count());
    }

    public function testFlushReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->cache->flush());
    }

    // ── flushExpired ──────────────────────────────────────────────────────────

    public function testFlushExpiredRemovesOnlyExpiredEntries(): void
    {
        $this->cache->set('live', 'data', 3600);
        $this->db->exec(
            "INSERT INTO cache_entries (cache_key, cache_value, expires_at)
             VALUES ('dead1', 'x', '2000-01-01 00:00:00'),
                    ('dead2', 'y', '2000-01-02 00:00:00')"
        );
        $deleted = $this->cache->flushExpired();
        $this->assertSame(2, $deleted);
        $this->assertSame('data', $this->cache->get('live'));
    }

    public function testFlushExpiredIgnoresNullExpiry(): void
    {
        $this->cache->set('forever', 'v', null);
        $this->assertSame(0, $this->cache->flushExpired());
        $this->assertSame('v', $this->cache->get('forever'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->cache->count());
        $this->cache->set('a', '1');
        $this->cache->set('b', '2');
        $this->assertSame(2, $this->cache->count());
    }

    public function testCountIncludesExpiredEntries(): void
    {
        $this->db->exec(
            "INSERT INTO cache_entries (cache_key, cache_value, expires_at)
             VALUES ('old', 'x', '2000-01-01 00:00:00')"
        );
        $this->assertSame(1, $this->cache->count());
    }
}
