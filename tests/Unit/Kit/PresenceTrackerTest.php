<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PresenceTracker;
use PDO;
use PHPUnit\Framework\TestCase;

final class PresenceTrackerTest extends TestCase
{
    private PDO $pdo;
    private PresenceTracker $pt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE presence_tracker (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                context      VARCHAR(255) NOT NULL DEFAULT \'\',
                last_seen_at DATETIME     NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, context)
            )
        ');
        $this->pt = new PresenceTracker($this->pdo);
    }

    // ── ping ──────────────────────────────────────────────────────────────────

    public function testPingCreatesRecord(): void
    {
        $this->pt->ping('user-1');
        $row = $this->pt->lastSeen('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
    }

    public function testPingIsIdempotent(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-1');
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM presence_tracker WHERE user_id = 'user-1'");
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testPingThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pt->ping('');
    }

    public function testPingWithContext(): void
    {
        $this->pt->ping('user-1', 'room:lobby');
        $row = $this->pt->lastSeen('user-1', 'room:lobby');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('room:lobby', $row['context']);
    }

    public function testPingCreatesDistinctRowsPerContext(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-1', 'room:lobby');
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM presence_tracker WHERE user_id = 'user-1'");
        $this->assertNotFalse($stmt);
        $this->assertSame(2, (int)$stmt->fetchColumn());
    }

    // ── lastSeen ──────────────────────────────────────────────────────────────

    public function testLastSeenReturnsNullWhenNotPinged(): void
    {
        $this->assertNull($this->pt->lastSeen('user-99'));
    }

    // ── isOnline ──────────────────────────────────────────────────────────────

    public function testIsOnlineTrueWhenRecentlyPinged(): void
    {
        $this->pt->ping('user-1');
        $this->assertTrue($this->pt->isOnline('user-1', 300));
    }

    public function testIsOnlineFalseWhenNotPinged(): void
    {
        $this->assertFalse($this->pt->isOnline('user-99', 300));
    }

    public function testIsOnlineFalseWhenLastSeenTooLongAgo(): void
    {
        // Manually insert an old record
        $this->pdo->exec(
            "INSERT INTO presence_tracker (user_id, context, last_seen_at, created_at)
             VALUES ('user-1', '', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
        );
        $this->assertFalse($this->pt->isOnline('user-1', 300));
    }

    // ── online ────────────────────────────────────────────────────────────────

    public function testOnlineReturnsRecentUsers(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-2');
        $this->assertCount(2, $this->pt->online(300));
    }

    public function testOnlineExcludesOldRecords(): void
    {
        $this->pdo->exec(
            "INSERT INTO presence_tracker (user_id, context, last_seen_at, created_at)
             VALUES ('user-old', '', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
        );
        $this->assertSame([], $this->pt->online(300));
    }

    public function testOnlineIsIsolatedByContext(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-2', 'room:lobby');
        $global = $this->pt->online(300, '');
        $lobby  = $this->pt->online(300, 'room:lobby');
        $this->assertCount(1, $global);
        $this->assertCount(1, $lobby);
    }

    // ── onlineIn ──────────────────────────────────────────────────────────────

    public function testOnlineInReturnsUsersInContext(): void
    {
        $this->pt->ping('user-1', 'room:lobby');
        $this->pt->ping('user-2', 'room:lobby');
        $this->pt->ping('user-3', 'room:general');
        $this->assertCount(2, $this->pt->onlineIn('room:lobby'));
        $this->assertCount(1, $this->pt->onlineIn('room:general'));
    }

    // ── countOnline ───────────────────────────────────────────────────────────

    public function testCountOnline(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-2');
        $this->assertSame(2, $this->pt->countOnline(300));
    }

    public function testCountOnlineIsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pt->countOnline(300));
    }

    // ── leave ─────────────────────────────────────────────────────────────────

    public function testLeaveRemovesRecord(): void
    {
        $this->pt->ping('user-1', 'room:lobby');
        $this->assertTrue($this->pt->leave('user-1', 'room:lobby'));
        $this->assertNull($this->pt->lastSeen('user-1', 'room:lobby'));
    }

    public function testLeaveReturnsFalseWhenNotPresent(): void
    {
        $this->assertFalse($this->pt->leave('user-99'));
    }

    public function testLeaveDoesNotRemoveOtherContexts(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-1', 'room:lobby');
        $this->pt->leave('user-1', 'room:lobby');
        $this->assertNotNull($this->pt->lastSeen('user-1')); // global still present
    }

    // ── removeUser ────────────────────────────────────────────────────────────

    public function testRemoveUserDeletesAllContexts(): void
    {
        $this->pt->ping('user-1');
        $this->pt->ping('user-1', 'room:lobby');
        $deleted = $this->pt->removeUser('user-1');
        $this->assertSame(2, $deleted);
        $this->assertNull($this->pt->lastSeen('user-1'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRecords(): void
    {
        $this->pdo->exec(
            "INSERT INTO presence_tracker (user_id, context, last_seen_at, created_at)
             VALUES ('user-1', '', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
        );
        $deleted = $this->pt->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteRecent(): void
    {
        $this->pt->ping('user-1');
        $deleted = $this->pt->purgeOlderThan('2020-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
