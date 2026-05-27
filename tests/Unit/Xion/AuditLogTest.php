<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\AuditLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditLog.
 */
final class AuditLogTest extends TestCase
{
    private PDO $db;
    private AuditLog $al;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE audit_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                action        VARCHAR(100) NOT NULL,
                actor_id      VARCHAR(255) NOT NULL DEFAULT \'\',
                resource_type VARCHAR(100) NOT NULL DEFAULT \'\',
                resource_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                context       TEXT         NOT NULL DEFAULT \'{}\',
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->al = new AuditLog($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->al->record('user.login', 'user-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $this->al->record('post.delete', 'admin-1', 'post', '42', ['reason' => 'spam']);
        $entries = $this->al->forActor('admin-1');
        $this->assertCount(1, $entries);
        $this->assertSame('post.delete', $entries[0]['action']);
        $this->assertSame('post', $entries[0]['resource_type']);
        $this->assertSame('42', $entries[0]['resource_id']);
        $this->assertSame(['reason' => 'spam'], $entries[0]['context']);
    }

    public function testRecordThrowsOnEmptyAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('');
    }

    public function testRecordWithEmptyContextStoresEmptyArray(): void
    {
        $this->al->record('user.login', 'user-1');
        $entries = $this->al->recent(1);
        $this->assertSame([], $entries[0]['context']);
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsNewestFirst(): void
    {
        $this->al->record('a.1', 'user-1');
        $this->al->record('a.2', 'user-1');
        $entries = $this->al->recent(10);
        $this->assertSame('a.2', $entries[0]['action']);
        $this->assertSame('a.1', $entries[1]['action']);
    }

    public function testRecentRespectsLimit(): void
    {
        $this->al->record('a.1', 'u');
        $this->al->record('a.2', 'u');
        $this->al->record('a.3', 'u');
        $this->assertCount(2, $this->al->recent(2));
    }

    public function testRecentReturnsEmptyWhenNoEntries(): void
    {
        $this->assertSame([], $this->al->recent());
    }

    // ── forActor ──────────────────────────────────────────────────────────────

    public function testForActorFiltersCorrectly(): void
    {
        $this->al->record('a.1', 'user-1');
        $this->al->record('a.2', 'user-2');
        $this->assertCount(1, $this->al->forActor('user-1'));
    }

    public function testForActorReturnsEmptyForUnknownActor(): void
    {
        $this->assertSame([], $this->al->forActor('nobody'));
    }

    // ── forResource ───────────────────────────────────────────────────────────

    public function testForResourceFiltersCorrectly(): void
    {
        $this->al->record('post.view', 'u', 'post', '1');
        $this->al->record('post.view', 'u', 'post', '2');
        $this->al->record('post.edit', 'u', 'post', '1');
        $this->assertCount(2, $this->al->forResource('post', '1'));
    }

    public function testForResourceReturnsEmptyForUnknownResource(): void
    {
        $this->assertSame([], $this->al->forResource('post', '999'));
    }

    // ── forAction ─────────────────────────────────────────────────────────────

    public function testForActionFiltersCorrectly(): void
    {
        $this->al->record('user.login', 'user-1');
        $this->al->record('user.login', 'user-2');
        $this->al->record('user.logout', 'user-1');
        $this->assertCount(2, $this->al->forAction('user.login'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAllEntries(): void
    {
        $this->al->record('a.1', 'u');
        $this->al->record('a.2', 'u');
        $this->assertSame(2, $this->al->count());
    }

    public function testCountByActor(): void
    {
        $this->al->record('a.1', 'user-1');
        $this->al->record('a.2', 'user-2');
        $this->assertSame(1, $this->al->count('user-1'));
    }

    public function testCountByAction(): void
    {
        $this->al->record('user.login', 'u1');
        $this->al->record('user.login', 'u2');
        $this->al->record('user.logout', 'u1');
        $this->assertSame(2, $this->al->count(null, 'user.login'));
    }

    public function testCountByActorAndAction(): void
    {
        $this->al->record('user.login', 'user-1');
        $this->al->record('user.login', 'user-2');
        $this->al->record('user.logout', 'user-1');
        $this->assertSame(1, $this->al->count('user-1', 'user.login'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $this->al->record('a.1', 'u');
        $this->db->exec(
            "UPDATE audit_log SET created_at = datetime('now', '-91 days')"
        );
        $this->assertSame(1, $this->al->purgeOlderThan(90));
        $this->assertSame(0, $this->al->count());
    }

    public function testPurgeOlderThanPreservesRecentEntries(): void
    {
        $this->al->record('a.1', 'u');
        $this->assertSame(0, $this->al->purgeOlderThan(90));
    }
}
