<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Subscription;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Subscription using an in-memory SQLite database.
 */
final class SubscriptionTest extends TestCase
{
    private PDO $db;
    private Subscription $sub;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE subscriptions (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL UNIQUE,
                plan       VARCHAR(64)  NOT NULL,
                status     VARCHAR(16)  NOT NULL DEFAULT \'active\',
                expires_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE subscription_history (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                plan       VARCHAR(64)  NOT NULL,
                action     VARCHAR(32)  NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->sub = new Subscription($this->db);
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    public function testSubscribeCreatesActiveSubscription(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->assertTrue($this->sub->isActive('user:1'));
    }

    public function testSubscribeSetsCurrentPlan(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->assertSame('pro', $this->sub->currentPlan('user:1'));
    }

    public function testSubscribeWithExpiryStoresExpiresAt(): void
    {
        $this->sub->subscribe('user:1', 'pro', expiresIn: 3600);
        $stmt = $this->db->query("SELECT expires_at FROM subscriptions WHERE user_id = 'user:1'");
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testSubscribeReplacesExistingSubscription(): void
    {
        $this->sub->subscribe('user:1', 'free');
        $this->sub->subscribe('user:1', 'pro');
        $this->assertSame('pro', $this->sub->currentPlan('user:1'));
    }

    public function testSubscribeRecordsHistory(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $history = $this->sub->history('user:1');
        $this->assertSame('subscribe', $history[0]['action']);
    }

    // ── isActive ──────────────────────────────────────────────────────────────

    public function testIsActiveReturnsFalseForNoSubscription(): void
    {
        $this->assertFalse($this->sub->isActive('user:1'));
    }

    public function testIsActiveReturnsFalseForExpiredSubscription(): void
    {
        $this->sub->subscribe('user:1', 'pro', expiresIn: 3600);
        $this->db->exec("UPDATE subscriptions SET expires_at = '2000-01-01 00:00:00' WHERE user_id = 'user:1'");
        $this->assertFalse($this->sub->isActive('user:1'));
    }

    public function testIsActiveReturnsFalseForCancelledSubscription(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->sub->cancel('user:1');
        $this->assertFalse($this->sub->isActive('user:1'));
    }

    // ── currentPlan ───────────────────────────────────────────────────────────

    public function testCurrentPlanReturnsNullForNoSubscription(): void
    {
        $this->assertNull($this->sub->currentPlan('user:1'));
    }

    // ── changePlan ────────────────────────────────────────────────────────────

    public function testChangePlanUpdatesPlan(): void
    {
        $this->sub->subscribe('user:1', 'free');
        $this->sub->changePlan('user:1', 'enterprise');
        $this->assertSame('enterprise', $this->sub->currentPlan('user:1'));
    }

    public function testChangePlanReturnsTrueOnSuccess(): void
    {
        $this->sub->subscribe('user:1', 'free');
        $this->assertTrue($this->sub->changePlan('user:1', 'pro'));
    }

    public function testChangePlanReturnsFalseWhenNoSubscription(): void
    {
        $this->assertFalse($this->sub->changePlan('user:1', 'pro'));
    }

    public function testChangePlanRecordsHistory(): void
    {
        $this->sub->subscribe('user:1', 'free');
        $this->sub->changePlan('user:1', 'pro');
        $history = $this->sub->history('user:1');
        $this->assertSame('change', $history[0]['action']);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelReturnsTrueOnSuccess(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->assertTrue($this->sub->cancel('user:1'));
    }

    public function testCancelReturnsFalseWhenNoSubscription(): void
    {
        $this->assertFalse($this->sub->cancel('user:1'));
    }

    public function testCancelReturnsFalseWhenAlreadyCancelled(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->sub->cancel('user:1');
        $this->assertFalse($this->sub->cancel('user:1'));
    }

    public function testCancelRecordsHistory(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->sub->cancel('user:1');
        $history = $this->sub->history('user:1');
        $this->assertSame('cancel', $history[0]['action']);
    }

    // ── renew ─────────────────────────────────────────────────────────────────

    public function testRenewActivatesCancelledSubscription(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->sub->cancel('user:1');
        $this->sub->renew('user:1', 86400);
        $this->assertTrue($this->sub->isActive('user:1'));
    }

    public function testRenewReturnsTrueOnSuccess(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->assertTrue($this->sub->renew('user:1', 86400));
    }

    public function testRenewReturnsFalseWhenNoSubscription(): void
    {
        $this->assertFalse($this->sub->renew('user:1', 86400));
    }

    public function testRenewRecordsHistory(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->sub->renew('user:1', 86400);
        $history = $this->sub->history('user:1');
        $this->assertSame('renew', $history[0]['action']);
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $this->sub->subscribe('user:1', 'free');
        $this->sub->changePlan('user:1', 'pro');
        $history = $this->sub->history('user:1');
        $this->assertSame('change', $history[0]['action']);
        $this->assertSame('subscribe', $history[1]['action']);
    }

    public function testHistoryIsUserIsolated(): void
    {
        $this->sub->subscribe('user:1', 'pro');
        $this->assertEmpty($this->sub->history('user:2'));
    }
}
