<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\SubscriptionPlan;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTest extends TestCase
{
    private PDO $pdo;
    private SubscriptionPlan $sp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE subscription_plans (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                plan         VARCHAR(100) NOT NULL,
                amount       INTEGER      NOT NULL DEFAULT 0,
                status       VARCHAR(20)  NOT NULL DEFAULT \'active\',
                auto_renew   INTEGER      NOT NULL DEFAULT 1,
                starts_at    DATETIME     NOT NULL,
                expires_at   DATETIME     NULL,
                cancelled_at DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sp = new SubscriptionPlan($this->pdo);
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    public function testSubscribeReturnsId(): void
    {
        $id = $this->sp->subscribe('user-1', 'pro', 999, '2026-07-01 00:00:00');
        $this->assertGreaterThan(0, $id);
    }

    public function testSubscribeStoresFields(): void
    {
        $id  = $this->sp->subscribe('user-1', 'pro', 999, '2026-07-01 00:00:00');
        $row = $this->sp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pro', $row['plan']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(999, (int)$row['amount']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SubscriptionPlan::STATUS_ACTIVE, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-07-01 00:00:00', $row['expires_at']);
    }

    public function testSubscribeThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sp->subscribe('', 'pro');
    }

    public function testSubscribeThrowsOnEmptyPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sp->subscribe('user-1', '');
    }

    public function testSubscribeAllowsNullExpiry(): void
    {
        $id  = $this->sp->subscribe('user-1', 'lifetime');
        $row = $this->sp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['expires_at']);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->sp->get(9999));
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsActiveSubscription(): void
    {
        $id  = $this->sp->subscribe('user-1', 'pro', 999, '2026-07-01 00:00:00');
        $row = $this->sp->current('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
    }

    public function testCurrentReturnsTrial(): void
    {
        $id = $this->sp->subscribe('user-1', 'trial', 0, '2026-06-15 00:00:00', '', SubscriptionPlan::STATUS_TRIAL);
        $row = $this->sp->current('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
    }

    public function testCurrentReturnsNullWhenNone(): void
    {
        $this->assertNull($this->sp->current('user-99'));
    }

    public function testCurrentReturnsNullAfterCancel(): void
    {
        $id = $this->sp->subscribe('user-1', 'pro', 999);
        $this->sp->cancelNow($id);
        $this->assertNull($this->sp->current('user-1'));
    }

    // ── isActive ──────────────────────────────────────────────────────────────

    public function testIsActiveTrueForValidSubscription(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->assertTrue($this->sp->isActive('user-1', '2026-06-01 12:00:00'));
    }

    public function testIsActiveFalseAfterExpiry(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999, '2026-06-01 00:00:00', '2026-01-01 00:00:00');
        $this->assertFalse($this->sp->isActive('user-1', '2026-06-02 00:00:00'));
    }

    public function testIsActiveFalseAfterCancel(): void
    {
        $id = $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->sp->cancelNow($id);
        $this->assertFalse($this->sp->isActive('user-1', '2026-06-01 12:00:00'));
    }

    public function testIsActiveFalseBeforeStartsAt(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00', '2026-07-01 00:00:00');
        $this->assertFalse($this->sp->isActive('user-1', '2026-06-01 12:00:00'));
    }

    public function testIsActiveTrueForLifetimePlan(): void
    {
        $this->sp->subscribe('user-1', 'lifetime', 0, null, '2020-01-01 00:00:00');
        $this->assertTrue($this->sp->isActive('user-1', '2099-01-01 12:00:00'));
    }

    // ── cancelAtEnd ───────────────────────────────────────────────────────────

    public function testCancelAtEndSetsAutoRenewFalse(): void
    {
        $id  = $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00');
        $this->assertTrue($this->sp->cancelAtEnd($id));
        $row = $this->sp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['auto_renew']);
        // Status should still be active
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SubscriptionPlan::STATUS_ACTIVE, $row['status']);
    }

    public function testCancelAtEndReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sp->cancelAtEnd(9999));
    }

    // ── cancelNow ─────────────────────────────────────────────────────────────

    public function testCancelNowChangesStatus(): void
    {
        $id  = $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00');
        $this->assertTrue($this->sp->cancelNow($id, '2026-06-15 10:00:00'));
        $row = $this->sp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SubscriptionPlan::STATUS_CANCELLED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-15 10:00:00', $row['cancelled_at']);
    }

    public function testCancelNowReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sp->cancelNow(9999));
    }

    // ── changePlan ────────────────────────────────────────────────────────────

    public function testChangePlanCancelsOldAndCreatesNew(): void
    {
        $id1 = $this->sp->subscribe('user-1', 'basic', 499, '2099-01-01 00:00:00');
        $id2 = $this->sp->changePlan($id1, 'pro', 999, '2099-01-01 00:00:00');
        $this->assertNotSame($id1, $id2);
        $old = $this->sp->get($id1);
        $this->assertNotNull($old);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SubscriptionPlan::STATUS_CANCELLED, $old['status']);
        $new = $this->sp->current('user-1');
        $this->assertNotNull($new);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pro', $new['plan']);
    }

    public function testChangePlanThrowsForMissingId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->sp->changePlan(9999, 'pro');
    }

    // ── expireStale ───────────────────────────────────────────────────────────

    public function testExpireStaleMarksExpiredSubscriptions(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999, '2026-06-01 00:00:00', '2020-01-01 00:00:00');
        $count = $this->sp->expireStale('2026-06-02 00:00:00');
        $this->assertSame(1, $count);
        $row = $this->sp->current('user-1');
        $this->assertNull($row);
    }

    public function testExpireStaleDoesNotExpireNonExpired(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999, '2099-01-01 00:00:00', '2020-01-01 00:00:00');
        $count = $this->sp->expireStale('2026-06-02 00:00:00');
        $this->assertSame(0, $count);
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllStatuses(): void
    {
        $id1 = $this->sp->subscribe('user-1', 'basic', 499, '2025-01-01 00:00:00', '2024-01-01 00:00:00');
        $id2 = $this->sp->subscribe('user-1', 'pro', 999, '2026-07-01 00:00:00');
        $this->sp->expireStale('2025-06-01 00:00:00');
        $list = $this->sp->history('user-1');
        $this->assertCount(2, $list);
        unset($id1, $id2);
    }

    public function testHistoryIsIsolatedByUser(): void
    {
        $this->sp->subscribe('user-1', 'pro', 999);
        $this->sp->subscribe('user-2', 'pro', 999);
        $this->assertCount(1, $this->sp->history('user-1'));
    }
}
