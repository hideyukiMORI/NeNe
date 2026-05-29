<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AccessLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccessLogTest extends TestCase
{
    private PDO $pdo;
    private AccessLog $al;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE access_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_type  VARCHAR(100) NOT NULL,
                resource_id    VARCHAR(255) NOT NULL,
                actor_id       VARCHAR(255) NOT NULL,
                action         VARCHAR(100) NOT NULL,
                ip_address     VARCHAR(45)  NULL,
                created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->al = new AccessLog($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->al->record('document', 'doc-1', 'user-1', 'read');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $id   = $this->al->record('document', 'doc-1', 'user-1', 'download', '203.0.113.1');
        $rows = $this->al->forResource('document', 'doc-1');
        $this->assertCount(1, $rows);
        $this->assertSame('document', $rows[0]['resource_type']);
        $this->assertSame('doc-1', $rows[0]['resource_id']);
        $this->assertSame('user-1', $rows[0]['actor_id']);
        $this->assertSame('download', $rows[0]['action']);
        $this->assertSame('203.0.113.1', $rows[0]['ip_address']);
        $this->assertSame($id, (int)$rows[0]['id']);
    }

    public function testRecordAllowsNullIp(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $rows = $this->al->forResource('document', 'doc-1');
        $this->assertNull($rows[0]['ip_address']);
    }

    public function testRecordThrowsOnEmptyResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('', 'doc-1', 'user-1', 'read');
    }

    public function testRecordThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('document', '', 'user-1', 'read');
    }

    public function testRecordThrowsOnEmptyActorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('document', 'doc-1', '', 'read');
    }

    public function testRecordThrowsOnEmptyAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('document', 'doc-1', 'user-1', '');
    }

    // ── forResource ───────────────────────────────────────────────────────────

    public function testForResourceFilters(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $this->al->record('document', 'doc-2', 'user-1', 'read');
        $this->al->record('invoice', 'doc-1', 'user-1', 'view');
        $rows = $this->al->forResource('document', 'doc-1');
        $this->assertCount(1, $rows);
    }

    public function testForResourceReturnsNewestFirst(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $this->al->record('document', 'doc-1', 'user-2', 'download');
        $rows = $this->al->forResource('document', 'doc-1');
        $this->assertSame('download', $rows[0]['action']);
    }

    public function testForResourceLimitApplied(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->al->record('document', 'doc-1', "user-{$i}", 'read');
        }

        $rows = $this->al->forResource('document', 'doc-1', 3);
        $this->assertCount(3, $rows);
    }

    public function testForResourceReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->al->forResource('document', 'missing'));
    }

    // ── byActor ───────────────────────────────────────────────────────────────

    public function testByActorFilters(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $this->al->record('invoice', 'inv-1', 'user-1', 'view');
        $this->al->record('document', 'doc-2', 'user-2', 'read');
        $rows = $this->al->byActor('user-1');
        $this->assertCount(2, $rows);
    }

    public function testByActorReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->al->byActor('unknown'));
    }

    // ── byAction ──────────────────────────────────────────────────────────────

    public function testByActionFilters(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $this->al->record('document', 'doc-2', 'user-2', 'download');
        $this->al->record('document', 'doc-3', 'user-3', 'read');
        $rows = $this->al->byAction('read');
        $this->assertCount(2, $rows);
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO access_log (resource_type, resource_id, actor_id, action, created_at)
             VALUES ('doc', '1', 'user-1', 'read', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO access_log (resource_type, resource_id, actor_id, action, created_at)
             VALUES ('doc', '2', 'user-1', 'read', '2020-06-01 00:00:00')"
        );
        $deleted = $this->al->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteRecentRows(): void
    {
        $this->al->record('document', 'doc-1', 'user-1', 'read');
        $deleted = $this->al->purgeOlderThan('2020-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
