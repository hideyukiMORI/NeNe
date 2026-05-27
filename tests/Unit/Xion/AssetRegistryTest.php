<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\AssetRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class AssetRegistryTest extends TestCase
{
    private PDO $pdo;
    private AssetRegistry $ar;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE asset_registry (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_type  VARCHAR(100) NOT NULL,
                name        TEXT         NOT NULL,
                serial      VARCHAR(255) NULL,
                assignee_id VARCHAR(255) NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'available\',
                meta        TEXT         NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE asset_assignments (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id    INTEGER      NOT NULL,
                assignee_id VARCHAR(255) NOT NULL,
                note        TEXT         NULL,
                assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                returned_at DATETIME     NULL
            )
        ');
        $this->ar = new AssetRegistry($this->pdo);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterReturnsId(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->assertGreaterThan(0, $id);
    }

    public function testRegisterStoresFields(): void
    {
        $id  = $this->ar->register('laptop', 'MacBook Pro', 'SN-001', '{"color":"silver"}');
        $row = $this->ar->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('laptop', $row['asset_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('MacBook Pro', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('SN-001', $row['serial']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AssetRegistry::STATUS_AVAILABLE, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['assignee_id']);
    }

    public function testRegisterThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ar->register('', 'MacBook Pro');
    }

    public function testRegisterThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ar->register('laptop', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->ar->get(9999));
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function testAssignChangesStatusAndAssignee(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->assertTrue($this->ar->assign($id, 'user-1', 'Issued on hire'));
        $row = $this->ar->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AssetRegistry::STATUS_ASSIGNED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['assignee_id']);
    }

    public function testAssignCreatesHistoryRecord(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1', 'Issued');
        $history = $this->ar->history($id);
        $this->assertCount(1, $history);
        $this->assertSame('user-1', $history[0]['assignee_id']);
    }

    public function testAssignReturnsFalseIfNotAvailable(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        // already assigned
        $this->assertFalse($this->ar->assign($id, 'user-2'));
    }

    public function testAssignThrowsOnEmptyAssigneeId(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->expectException(\InvalidArgumentException::class);
        $this->ar->assign($id, '');
    }

    // ── unassign ──────────────────────────────────────────────────────────────

    public function testUnassignChangesStatusToAvailable(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        $this->assertTrue($this->ar->unassign($id, 'Returned at offboarding'));
        $row = $this->ar->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AssetRegistry::STATUS_AVAILABLE, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['assignee_id']);
    }

    public function testUnassignSetsReturnedAt(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        $this->ar->unassign($id);
        $history = $this->ar->history($id);
        $this->assertNotNull($history[0]['returned_at']);
    }

    public function testUnassignReturnsFalseIfNotAssigned(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->assertFalse($this->ar->unassign($id));
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function testRetireChangesStatus(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->assertTrue($this->ar->retire($id));
        $row = $this->ar->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AssetRegistry::STATUS_RETIRED, $row['status']);
    }

    public function testRetireFromAssignedState(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        $this->assertTrue($this->ar->retire($id));
        $row = $this->ar->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AssetRegistry::STATUS_RETIRED, $row['status']);
    }

    public function testRetireReturnsFalseWhenAlreadyRetired(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->retire($id);
        $this->assertFalse($this->ar->retire($id));
    }

    // ── byType ────────────────────────────────────────────────────────────────

    public function testByTypeFilters(): void
    {
        $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->register('laptop', 'ThinkPad');
        $this->ar->register('monitor', 'LG 27"');
        $this->assertCount(2, $this->ar->byType('laptop'));
        $this->assertCount(1, $this->ar->byType('monitor'));
    }

    public function testByTypeReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ar->byType('phone'));
    }

    // ── assignedTo ────────────────────────────────────────────────────────────

    public function testAssignedToFilters(): void
    {
        $id1 = $this->ar->register('laptop', 'MacBook Pro');
        $id2 = $this->ar->register('monitor', 'LG 27"');
        $this->ar->register('laptop', 'ThinkPad');
        $this->ar->assign($id1, 'user-1');
        $this->ar->assign($id2, 'user-1');
        $this->assertCount(2, $this->ar->assignedTo('user-1'));
    }

    public function testAssignedToReturnsEmptyAfterUnassign(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        $this->ar->unassign($id);
        $this->assertSame([], $this->ar->assignedTo('user-1'));
    }

    // ── available ─────────────────────────────────────────────────────────────

    public function testAvailableReturnsOnlyAvailableAssets(): void
    {
        $id1 = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->register('laptop', 'ThinkPad');
        $this->ar->assign($id1, 'user-1');
        $this->assertCount(1, $this->ar->available());
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryRecordsMultipleAssignments(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->ar->assign($id, 'user-1');
        $this->ar->unassign($id);
        $this->ar->assign($id, 'user-2');
        $this->assertCount(2, $this->ar->history($id));
    }

    public function testHistoryReturnsEmptyWhenNeverAssigned(): void
    {
        $id = $this->ar->register('laptop', 'MacBook Pro');
        $this->assertSame([], $this->ar->history($id));
    }
}
