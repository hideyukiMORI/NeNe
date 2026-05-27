<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\Watchlist;
use PDO;
use PHPUnit\Framework\TestCase;

final class WatchlistTest extends TestCase
{
    private PDO $pdo;
    private Watchlist $wl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE watchlist (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                watched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, user_id)
            )
        ');
        $this->wl = new Watchlist($this->pdo);
    }

    // ── watch ─────────────────────────────────────────────────────────────────

    public function testWatchSubscribesUser(): void
    {
        $this->wl->watch('issue', '42', 'user-1');
        $this->assertTrue($this->wl->isWatching('issue', '42', 'user-1'));
    }

    public function testWatchIsIdempotent(): void
    {
        $this->wl->watch('issue', '42', 'user-1');
        $this->wl->watch('issue', '42', 'user-1');
        $this->assertSame(1, $this->wl->watcherCount('issue', '42'));
    }

    public function testWatchThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->watch('', '1', 'user-1');
    }

    public function testWatchThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->watch('issue', '', 'user-1');
    }

    public function testWatchThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->watch('issue', '1', '');
    }

    // ── unwatch ───────────────────────────────────────────────────────────────

    public function testUnwatchRemovesSubscription(): void
    {
        $this->wl->watch('issue', '42', 'user-1');
        $result = $this->wl->unwatch('issue', '42', 'user-1');
        $this->assertTrue($result);
        $this->assertFalse($this->wl->isWatching('issue', '42', 'user-1'));
    }

    public function testUnwatchReturnsFalseWhenNotWatching(): void
    {
        $this->assertFalse($this->wl->unwatch('issue', '42', 'user-1'));
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function testToggleAddsWatch(): void
    {
        $watching = $this->wl->toggle('issue', '1', 'user-1');
        $this->assertTrue($watching);
        $this->assertTrue($this->wl->isWatching('issue', '1', 'user-1'));
    }

    public function testToggleRemovesWatch(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $watching = $this->wl->toggle('issue', '1', 'user-1');
        $this->assertFalse($watching);
        $this->assertFalse($this->wl->isWatching('issue', '1', 'user-1'));
    }

    // ── isWatching ────────────────────────────────────────────────────────────

    public function testIsWatchingReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->wl->isWatching('issue', '1', 'user-1'));
    }

    // ── watchers ──────────────────────────────────────────────────────────────

    public function testWatchersReturnsAllRows(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '1', 'user-2');

        $watchers = $this->wl->watchers('issue', '1');
        $this->assertCount(2, $watchers);
        $this->assertArrayHasKey('user_id', $watchers[0]);
    }

    public function testWatchersReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->wl->watchers('issue', '99'));
    }

    public function testWatchersIsolatedByEntity(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '2', 'user-2');

        $this->assertCount(1, $this->wl->watchers('issue', '1'));
        $this->assertCount(1, $this->wl->watchers('issue', '2'));
    }

    // ── watcherCount ─────────────────────────────────────────────────────────

    public function testWatcherCountReturnsCorrectNumber(): void
    {
        $this->assertSame(0, $this->wl->watcherCount('issue', '1'));
        $this->wl->watch('issue', '1', 'user-1');
        $this->assertSame(1, $this->wl->watcherCount('issue', '1'));
        $this->wl->watch('issue', '1', 'user-2');
        $this->assertSame(2, $this->wl->watcherCount('issue', '1'));
    }

    // ── watching ──────────────────────────────────────────────────────────────

    public function testWatchingReturnsUserSubscriptions(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '2', 'user-1');
        $this->wl->watch('issue', '1', 'user-2');

        $items = $this->wl->watching('user-1');
        $this->assertCount(2, $items);
    }

    public function testWatchingReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->wl->watching('user-99'));
    }

    public function testWatchingThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->watching('');
    }

    // ── watcherIds ────────────────────────────────────────────────────────────

    public function testWatcherIdsReturnsUserIdList(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '1', 'user-2');

        $ids = $this->wl->watcherIds('issue', '1');
        sort($ids);
        $this->assertSame(['user-1', 'user-2'], $ids);
    }

    // ── clearEntity ───────────────────────────────────────────────────────────

    public function testClearEntityRemovesAllWatchers(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '1', 'user-2');
        $this->wl->watch('issue', '2', 'user-1');

        $n = $this->wl->clearEntity('issue', '1');
        $this->assertSame(2, $n);
        $this->assertSame(0, $this->wl->watcherCount('issue', '1'));
        $this->assertSame(1, $this->wl->watcherCount('issue', '2'));
    }

    // ── clearUser ─────────────────────────────────────────────────────────────

    public function testClearUserRemovesAllUserWatches(): void
    {
        $this->wl->watch('issue', '1', 'user-1');
        $this->wl->watch('issue', '2', 'user-1');
        $this->wl->watch('issue', '1', 'user-2');

        $n = $this->wl->clearUser('user-1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->wl->watching('user-1'));
        $this->assertCount(1, $this->wl->watching('user-2'));
    }

    public function testClearUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wl->clearUser('');
    }
}
