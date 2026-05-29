<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AuditLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    private PDO $pdo;
    private AuditLog $al;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE audit_logs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                action       VARCHAR(50)  NOT NULL,
                actor_id     VARCHAR(255) NOT NULL,
                before_data  TEXT         NULL,
                after_data   TEXT         NULL,
                ip_address   VARCHAR(45)  NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->al = new AuditLog($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->al->record('user', '42', AuditLog::ACTION_UPDATE, 'actor-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $id  = $this->al->record(
            'user',
            '42',
            AuditLog::ACTION_UPDATE,
            'actor-1',
            ['email' => 'old@x.com'],
            ['email' => 'new@x.com'],
            '127.0.0.1'
        );
        $row = $this->al->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user', $row['entity_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('42', $row['entity_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AuditLog::ACTION_UPDATE, $row['action']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('actor-1', $row['actor_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"email":"old@x.com"}', $row['before_data']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"email":"new@x.com"}', $row['after_data']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('127.0.0.1', $row['ip_address']);
    }

    public function testRecordNullBeforeAfterData(): void
    {
        $id  = $this->al->record('user', '42', AuditLog::ACTION_DELETE, 'actor-1');
        $row = $this->al->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['before_data']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['after_data']);
    }

    public function testRecordThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('', '1', 'update', 'actor');
    }

    public function testRecordThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('user', '', 'update', 'actor');
    }

    public function testRecordThrowsOnEmptyAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('user', '1', '', 'actor');
    }

    public function testRecordThrowsOnEmptyActorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->al->record('user', '1', 'update', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->al->find(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsNewestFirst(): void
    {
        $id1 = $this->al->record('user', '1', 'create', 'actor');
        $id2 = $this->al->record('user', '1', 'update', 'actor');
        $list = $this->al->forEntity('user', '1');
        $this->assertCount(2, $list);
        $this->assertSame($id2, (int)$list[0]['id']);
        $this->assertSame($id1, (int)$list[1]['id']);
    }

    public function testForEntityIsIsolatedByEntity(): void
    {
        $this->al->record('user', '1', 'create', 'actor');
        $this->al->record('user', '2', 'create', 'actor');
        $this->assertCount(1, $this->al->forEntity('user', '1'));
        $this->assertCount(1, $this->al->forEntity('user', '2'));
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->al->forEntity('user', '99'));
    }

    public function testForEntityRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->al->record('user', '1', 'update', 'actor');
        }
        $page1 = $this->al->forEntity('user', '1', 3, 0);
        $page2 = $this->al->forEntity('user', '1', 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── byActor ───────────────────────────────────────────────────────────────

    public function testByActorReturnsAllActionsForActor(): void
    {
        $this->al->record('user', '1', 'update', 'actor-1');
        $this->al->record('order', '5', 'delete', 'actor-1');
        $this->al->record('user', '2', 'create', 'actor-2');

        $this->assertCount(2, $this->al->byActor('actor-1'));
        $this->assertCount(1, $this->al->byActor('actor-2'));
    }

    public function testByActorReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->al->byActor('nobody'));
    }

    // ── ofAction ──────────────────────────────────────────────────────────────

    public function testOfActionReturnsMatchingActions(): void
    {
        $this->al->record('user', '1', 'create', 'actor');
        $this->al->record('user', '2', 'update', 'actor');
        $this->al->record('user', '3', 'delete', 'actor');

        $this->assertCount(1, $this->al->ofAction('create'));
        $this->assertCount(1, $this->al->ofAction('delete'));
        $this->assertSame([], $this->al->ofAction('purge'));
    }

    // ── countForEntity ────────────────────────────────────────────────────────

    public function testCountForEntity(): void
    {
        $this->al->record('user', '1', 'create', 'actor');
        $this->al->record('user', '1', 'update', 'actor');
        $this->al->record('user', '2', 'create', 'actor');
        $this->assertSame(2, $this->al->countForEntity('user', '1'));
        $this->assertSame(1, $this->al->countForEntity('user', '2'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThan(): void
    {
        $this->pdo->exec("INSERT INTO audit_logs (entity_type, entity_id, action, actor_id, created_at) VALUES ('user', '1', 'create', 'actor', '2020-01-01 00:00:00')");
        $this->al->record('user', '2', 'create', 'actor');

        $cutoff = new \DateTimeImmutable('2021-01-01 00:00:00');
        $count  = $this->al->purgeOlderThan($cutoff);
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->al->forEntity('user', '2'));
    }

    public function testPurgeOlderThanReturnsZeroWhenNone(): void
    {
        $cutoff = new \DateTimeImmutable('2000-01-01 00:00:00');
        $this->assertSame(0, $this->al->purgeOlderThan($cutoff));
    }
}
