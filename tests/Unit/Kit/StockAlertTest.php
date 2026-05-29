<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\StockAlert;
use PDO;
use PHPUnit\Framework\TestCase;

final class StockAlertTest extends TestCase
{
    private PDO $pdo;
    private StockAlert $sa;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE stock_alerts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                sku          VARCHAR(255) NOT NULL,
                threshold    INTEGER      NOT NULL DEFAULT 1,
                status       VARCHAR(20)  NOT NULL DEFAULT \'active\',
                triggered_at DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sa = new StockAlert($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresFields(): void
    {
        $id  = $this->sa->create('user-1', 'sku-1', 3);
        $row = $this->sa->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('sku-1', $row['sku']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3, (int)$row['threshold']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(StockAlert::STATUS_ACTIVE, $row['status']);
    }

    public function testCreateDefaultThresholdIsOne(): void
    {
        $id  = $this->sa->create('user-1', 'sku-1');
        $row = $this->sa->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['threshold']);
    }

    public function testCreateThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sa->create('', 'sku-1');
    }

    public function testCreateThrowsOnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sa->create('user-1', '');
    }

    public function testCreateThrowsOnZeroThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sa->create('user-1', 'sku-1', 0);
    }

    public function testCreateThrowsOnNegativeThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sa->create('user-1', 'sku-1', -1);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->sa->get(9999));
    }

    // ── activeFor ─────────────────────────────────────────────────────────────

    public function testActiveForReturnsActiveAlerts(): void
    {
        $this->sa->create('user-1', 'sku-1');
        $this->sa->create('user-1', 'sku-2');
        $this->assertCount(2, $this->sa->activeFor('user-1'));
    }

    public function testActiveForExcludesTriggered(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        $this->sa->markTriggered($id);
        $this->assertSame([], $this->sa->activeFor('user-1'));
    }

    public function testActiveForExcludesDismissed(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        $this->sa->dismiss($id);
        $this->assertSame([], $this->sa->activeFor('user-1'));
    }

    public function testActiveForIsIsolatedByUser(): void
    {
        $this->sa->create('user-1', 'sku-1');
        $this->sa->create('user-2', 'sku-1');
        $this->assertCount(1, $this->sa->activeFor('user-1'));
        $this->assertCount(1, $this->sa->activeFor('user-2'));
    }

    public function testActiveForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sa->activeFor('user-99'));
    }

    // ── pendingForSku ─────────────────────────────────────────────────────────

    public function testPendingForSkuReturnsActiveAlerts(): void
    {
        $this->sa->create('user-1', 'sku-1');
        $this->sa->create('user-2', 'sku-1');
        $this->assertCount(2, $this->sa->pendingForSku('sku-1'));
    }

    public function testPendingForSkuExcludesTriggered(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        $this->sa->markTriggered($id);
        $this->assertSame([], $this->sa->pendingForSku('sku-1'));
    }

    public function testPendingForSkuIsIsolatedBySku(): void
    {
        $this->sa->create('user-1', 'sku-1');
        $this->sa->create('user-1', 'sku-2');
        $this->assertCount(1, $this->sa->pendingForSku('sku-1'));
        $this->assertCount(1, $this->sa->pendingForSku('sku-2'));
    }

    public function testPendingForSkuReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sa->pendingForSku('sku-unknown'));
    }

    // ── pendingTriggers ───────────────────────────────────────────────────────

    public function testPendingTriggersReturnsAllActive(): void
    {
        $this->sa->create('user-1', 'sku-1');
        $this->sa->create('user-2', 'sku-2');
        $this->assertCount(2, $this->sa->pendingTriggers());
    }

    public function testPendingTriggersExcludesNonActive(): void
    {
        $id1 = $this->sa->create('user-1', 'sku-1');
        $id2 = $this->sa->create('user-2', 'sku-2');
        $this->sa->markTriggered($id1);
        $this->sa->dismiss($id2);
        $this->assertSame([], $this->sa->pendingTriggers());
    }

    public function testPendingTriggersReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sa->pendingTriggers());
    }

    // ── markTriggered ─────────────────────────────────────────────────────────

    public function testMarkTriggeredChangesStatus(): void
    {
        $id  = $this->sa->create('user-1', 'sku-1');
        $this->assertTrue($this->sa->markTriggered($id));
        $row = $this->sa->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(StockAlert::STATUS_TRIGGERED, $row['status']);
    }

    public function testMarkTriggeredSetsTriggeredAt(): void
    {
        $id  = $this->sa->create('user-1', 'sku-1');
        $this->sa->markTriggered($id);
        $row = $this->sa->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['triggered_at']);
    }

    public function testMarkTriggeredReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sa->markTriggered(9999));
    }

    // ── dismiss ───────────────────────────────────────────────────────────────

    public function testDismissChangesStatus(): void
    {
        $id  = $this->sa->create('user-1', 'sku-1');
        $this->assertTrue($this->sa->dismiss($id));
        $row = $this->sa->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(StockAlert::STATUS_DISMISSED, $row['status']);
    }

    public function testDismissReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sa->dismiss(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRow(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        $this->assertTrue($this->sa->delete($id));
        $this->assertNull($this->sa->get($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sa->delete(9999));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeDeletesOldNonActiveRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO stock_alerts (user_id, sku, threshold, status, created_at)
             VALUES ('user-1', 'sku-1', 1, 'triggered', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO stock_alerts (user_id, sku, threshold, status, created_at)
             VALUES ('user-2', 'sku-2', 1, 'dismissed', '2020-01-01 00:00:00')"
        );
        $deleted = $this->sa->purge('2020-06-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeDoesNotDeleteActiveAlerts(): void
    {
        $id = $this->sa->create('user-1', 'sku-1');
        // Even if created_at is old, active alerts must not be purged
        $this->pdo->exec("UPDATE stock_alerts SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->sa->purge('2020-06-01 00:00:00');
        $this->assertSame(0, $deleted);
        $this->assertNotNull($this->sa->get($id));
    }

    public function testPurgeDoesNotDeleteRecentRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO stock_alerts (user_id, sku, threshold, status, created_at)
             VALUES ('user-1', 'sku-1', 1, 'triggered', '2025-01-01 00:00:00')"
        );
        $deleted = $this->sa->purge('2020-06-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
