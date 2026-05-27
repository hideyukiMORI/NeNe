<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UserActivity;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserActivity.
 */
final class UserActivityTest extends TestCase
{
    private PDO $db;
    private UserActivity $ua;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_last_seen (
                user_id   VARCHAR(255) NOT NULL PRIMARY KEY,
                last_seen DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE user_activity_log (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id   VARCHAR(255) NOT NULL,
                action    VARCHAR(100) NOT NULL,
                meta      TEXT         NOT NULL DEFAULT \'\',
                logged_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ua = new UserActivity($this->db);
    }

    // ── touch / lastSeen ──────────────────────────────────────────────────────

    public function testTouchSetsLastSeen(): void
    {
        $this->ua->touch('user-1');
        $ts = $this->ua->lastSeen('user-1');
        $this->assertNotNull($ts);
        $this->assertInstanceOf(\DateTimeImmutable::class, $ts);
    }

    public function testLastSeenReturnsNullWhenNeverTouched(): void
    {
        $this->assertNull($this->ua->lastSeen('user-1'));
    }

    public function testTouchIsIdempotent(): void
    {
        $this->ua->touch('user-1');
        $this->ua->touch('user-1');
        $count = (int)$this->db->query('SELECT COUNT(*) FROM user_last_seen')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testTouchUpdatesTimestamp(): void
    {
        // Set an old last_seen manually
        $old = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO user_last_seen (user_id, last_seen) VALUES ('user-1', '{$old}')");

        $this->ua->touch('user-1');
        $ts = $this->ua->lastSeen('user-1');
        $this->assertNotNull($ts);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertGreaterThan(new \DateTimeImmutable($old), $ts);
    }

    public function testTouchThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ua->touch('');
    }

    // ── isRecentlyActive ──────────────────────────────────────────────────────

    public function testIsRecentlyActiveReturnsTrueAfterTouch(): void
    {
        $this->ua->touch('user-1');
        $this->assertTrue($this->ua->isRecentlyActive('user-1', 60));
    }

    public function testIsRecentlyActiveReturnsFalseWhenNeverSeen(): void
    {
        $this->assertFalse($this->ua->isRecentlyActive('user-1', 300));
    }

    public function testIsRecentlyActiveReturnsFalseWhenTooOld(): void
    {
        $old = (new \DateTimeImmutable())->modify('-10 minutes')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO user_last_seen (user_id, last_seen) VALUES ('user-1', '{$old}')");
        $this->assertFalse($this->ua->isRecentlyActive('user-1', 60));
    }

    // ── log ───────────────────────────────────────────────────────────────────

    public function testLogReturnsId(): void
    {
        $id = $this->ua->log('user-1', 'login');
        $this->assertGreaterThan(0, $id);
    }

    public function testLogStoresAction(): void
    {
        $this->ua->log('user-1', 'export_requested');
        $rows = $this->ua->recentActions('user-1', 1);
        $this->assertSame('export_requested', $rows[0]['action']);
    }

    public function testLogStoresMetaAsJson(): void
    {
        $this->ua->log('user-1', 'viewed_page', ['path' => '/dashboard']);
        $rows = $this->ua->recentActions('user-1', 1);
        $this->assertStringContainsString('dashboard', $rows[0]['meta']);
    }

    public function testLogThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ua->log('', 'login');
    }

    public function testLogThrowsOnEmptyAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ua->log('user-1', '');
    }

    // ── recentActions ─────────────────────────────────────────────────────────

    public function testRecentActionsReturnsNewestFirst(): void
    {
        $this->ua->log('user-1', 'a');
        $this->ua->log('user-1', 'b');
        $this->ua->log('user-1', 'c');
        $rows = $this->ua->recentActions('user-1', 2);
        $this->assertCount(2, $rows);
        $this->assertSame('c', $rows[0]['action']);
        $this->assertSame('b', $rows[1]['action']);
    }

    public function testRecentActionsReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->ua->recentActions('unknown'));
    }

    // ── countActions ──────────────────────────────────────────────────────────

    public function testCountActionsTotal(): void
    {
        $this->ua->log('user-1', 'login');
        $this->ua->log('user-1', 'login');
        $this->ua->log('user-1', 'export');
        $this->assertSame(3, $this->ua->countActions('user-1'));
    }

    public function testCountActionsFilteredByAction(): void
    {
        $this->ua->log('user-1', 'login');
        $this->ua->log('user-1', 'login');
        $this->ua->log('user-1', 'export');
        $this->assertSame(2, $this->ua->countActions('user-1', 'login'));
        $this->assertSame(1, $this->ua->countActions('user-1', 'export'));
        $this->assertSame(0, $this->ua->countActions('user-1', 'missing'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $old = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO user_activity_log (user_id, action, logged_at)
                         VALUES ('user-1', 'old_action', '{$old}')");
        $this->ua->log('user-1', 'recent_action');

        $deleted = $this->ua->purgeOlderThan(1);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->ua->countActions('user-1'));
    }
}
