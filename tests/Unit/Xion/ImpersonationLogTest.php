<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ImpersonationLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class ImpersonationLogTest extends TestCase
{
    private PDO $pdo;
    private ImpersonationLog $il;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE impersonation_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id    VARCHAR(255) NOT NULL,
                target_id   VARCHAR(255) NOT NULL,
                reason      TEXT         NULL,
                started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at    DATETIME     NULL
            )
        ');
        $this->il = new ImpersonationLog($this->pdo);
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartReturnsId(): void
    {
        $id = $this->il->start('admin-1', 'user-42');
        $this->assertGreaterThan(0, $id);
    }

    public function testStartStoresFields(): void
    {
        $id  = $this->il->start('admin-1', 'user-42', 'Support ticket #99');
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('admin-1', $row['admin_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-42', $row['target_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Support ticket #99', $row['reason']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['ended_at']);
    }

    public function testStartThrowsOnEmptyAdminId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->il->start('', 'user-42');
    }

    public function testStartThrowsOnEmptyTargetId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->il->start('admin-1', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->il->get(9999));
    }

    // ── end ───────────────────────────────────────────────────────────────────

    public function testEndSetsEndedAt(): void
    {
        $id  = $this->il->start('admin-1', 'user-42');
        $this->assertTrue($this->il->end($id));
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['ended_at']);
    }

    public function testEndReturnsFalseIfAlreadyEnded(): void
    {
        $id = $this->il->start('admin-1', 'user-42');
        $this->il->end($id);
        $this->assertFalse($this->il->end($id)); // already ended
    }

    public function testEndReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->il->end(9999));
    }

    // ── active ────────────────────────────────────────────────────────────────

    public function testActiveReturnsOnlyOpenSessions(): void
    {
        $id1 = $this->il->start('admin-1', 'user-1');
        $this->il->start('admin-2', 'user-2');
        $this->il->end($id1);
        $active = $this->il->active();
        $this->assertCount(1, $active);
        $this->assertSame('admin-2', $active[0]['admin_id']);
    }

    public function testActiveFiltersByAdmin(): void
    {
        $this->il->start('admin-1', 'user-1');
        $this->il->start('admin-1', 'user-2');
        $this->il->start('admin-2', 'user-3');
        $active = $this->il->active('admin-1');
        $this->assertCount(2, $active);
    }

    public function testActiveReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->il->active());
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllSessionsForTarget(): void
    {
        $id1 = $this->il->start('admin-1', 'user-42');
        $this->il->end($id1);
        $this->il->start('admin-2', 'user-42');
        $this->il->start('admin-1', 'user-99');
        $history = $this->il->history('user-42');
        $this->assertCount(2, $history);
    }

    public function testHistoryReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->il->history('unknown'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesEndedSessions(): void
    {
        $this->pdo->exec(
            "INSERT INTO impersonation_log (admin_id, target_id, started_at, ended_at)
             VALUES ('admin-1', 'user-1', '2020-01-01 00:00:00', '2020-01-01 01:00:00')"
        );
        $deleted = $this->il->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteActiveSession(): void
    {
        $id = $this->il->start('admin-1', 'user-1');
        $this->pdo->exec("UPDATE impersonation_log SET started_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->il->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
