<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\OnlineStatus;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OnlineStatus.
 */
final class OnlineStatusTest extends TestCase
{
    private PDO $db;
    private OnlineStatus $os;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE online_status (
                user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
                last_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_offline TINYINT(1)   NOT NULL DEFAULT 0
            )
        ');
        // Use short thresholds for tests: 10s online, 30s idle
        $this->os = new OnlineStatus($this->db, onlineSeconds: 10, idleSeconds: 30);
    }

    // ── heartbeat ─────────────────────────────────────────────────────────────

    public function testHeartbeatMakesUserOnline(): void
    {
        $this->os->heartbeat('user-1');
        $this->assertSame('online', $this->os->status('user-1'));
    }

    public function testHeartbeatIsIdempotent(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->heartbeat('user-1');
        $stmt = $this->db->query("SELECT COUNT(*) FROM online_status WHERE user_id = 'user-1'");
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testHeartbeatClearsOfflineFlag(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->setOffline('user-1');
        $this->os->heartbeat('user-1'); // come back online
        $this->assertSame('online', $this->os->status('user-1'));
    }

    public function testHeartbeatThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->os->heartbeat('');
    }

    // ── setOffline ────────────────────────────────────────────────────────────

    public function testSetOfflineMakesUserOffline(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->setOffline('user-1');
        $this->assertSame('offline', $this->os->status('user-1'));
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusIsOfflineForUnseenUser(): void
    {
        $this->assertSame('offline', $this->os->status('user-1'));
    }

    public function testStatusIsIdleWhenBetweenThresholds(): void
    {
        // Insert a last_seen that's 15 seconds ago: > online (10s) but < idle (30s)
        $lastSeen = (new \DateTimeImmutable())->modify('-15 seconds')->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO online_status (user_id, last_seen, is_offline) VALUES ('user-1', :ts, 0)"
        )->execute([':ts' => $lastSeen]);
        $this->assertSame('idle', $this->os->status('user-1'));
    }

    public function testStatusIsOfflineWhenBeyondIdleThreshold(): void
    {
        // Insert a last_seen that's 60 seconds ago: > idle (30s)
        $lastSeen = (new \DateTimeImmutable())->modify('-60 seconds')->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO online_status (user_id, last_seen, is_offline) VALUES ('user-1', :ts, 0)"
        )->execute([':ts' => $lastSeen]);
        $this->assertSame('offline', $this->os->status('user-1'));
    }

    // ── lastSeen ──────────────────────────────────────────────────────────────

    public function testLastSeenReturnsDateTimeImmutable(): void
    {
        $this->os->heartbeat('user-1');
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->os->lastSeen('user-1'));
    }

    public function testLastSeenReturnsNullForUnseenUser(): void
    {
        $this->assertNull($this->os->lastSeen('user-1'));
    }

    // ── listOnline / listIdle ─────────────────────────────────────────────────

    public function testListOnlineReturnsRecentUsers(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->heartbeat('user-2');
        $online = $this->os->listOnline();
        $this->assertContains('user-1', $online);
        $this->assertContains('user-2', $online);
    }

    public function testListOnlineExcludesOfflineUsers(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->setOffline('user-1');
        $this->assertNotContains('user-1', $this->os->listOnline());
    }

    public function testListIdleReturnsIdleUsers(): void
    {
        $lastSeen = (new \DateTimeImmutable())->modify('-15 seconds')->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO online_status (user_id, last_seen, is_offline) VALUES ('user-1', :ts, 0)"
        )->execute([':ts' => $lastSeen]);
        $this->assertContains('user-1', $this->os->listIdle());
        $this->assertNotContains('user-1', $this->os->listOnline());
    }

    // ── purgeStale ────────────────────────────────────────────────────────────

    public function testPurgeStaleRemovesOldRecords(): void
    {
        $oldTs = (new \DateTimeImmutable())->modify('-60 seconds')->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO online_status (user_id, last_seen, is_offline) VALUES ('old-user', :ts, 0)"
        )->execute([':ts' => $oldTs]);
        $this->os->heartbeat('user-1'); // recent — should not be purged
        $purged = $this->os->purgeStale();
        $this->assertGreaterThan(0, $purged);
        $this->assertNotNull($this->os->lastSeen('user-1')); // recent user still present
    }

    public function testPurgeStaleRemovesExplicitlyOfflineUsers(): void
    {
        $this->os->heartbeat('user-1');
        $this->os->setOffline('user-1');
        $purged = $this->os->purgeStale();
        $this->assertGreaterThan(0, $purged);
        $this->assertNull($this->os->lastSeen('user-1'));
    }
}
