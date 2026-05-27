<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Subscription;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Subscription.
 */
final class SubscriptionTest extends TestCase
{
    private PDO $db;
    private Subscription $sub;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE subscriptions (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id                VARCHAR(255) NOT NULL,
                plan                   VARCHAR(100) NOT NULL,
                status                 VARCHAR(20)  NOT NULL DEFAULT \'active\',
                trial_ends_at          DATETIME     DEFAULT NULL,
                current_period_ends_at DATETIME     NOT NULL,
                cancelled_at           DATETIME     DEFAULT NULL,
                created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, plan)
            )
        ');
        $this->sub = new Subscription($this->db);
    }

    private function future(string $modify = '+1 month'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($modify);
    }

    private function past(string $modify = '-1 day'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($modify);
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    public function testSubscribeReturnsId(): void
    {
        $id = $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertGreaterThan(0, $id);
    }

    public function testSubscribeStatusIsActive(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertSame('active', $this->sub->status('user-1', 'pro'));
    }

    public function testSubscribeWithTrialStatusIsTrialing(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future(), $this->future('+14 days'));
        $this->assertSame('trialing', $this->sub->status('user-1', 'pro'));
    }

    public function testSubscribeIsUpsert(): void
    {
        $id1 = $this->sub->subscribe('user-1', 'pro', $this->future());
        $id2 = $this->sub->subscribe('user-1', 'pro', $this->future('+2 months'));
        $this->assertSame($id1, $id2);
    }

    public function testSubscribeThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sub->subscribe('', 'pro', $this->future());
    }

    public function testSubscribeThrowsOnEmptyPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sub->subscribe('user-1', '', $this->future());
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelSetsStatusToCancelled(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertTrue($this->sub->cancel('user-1', 'pro'));
        $this->assertSame('cancelled', $this->sub->status('user-1', 'pro'));
    }

    public function testCancelReturnsFalseIfAlreadyCancelled(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->sub->cancel('user-1', 'pro');
        $this->assertFalse($this->sub->cancel('user-1', 'pro'));
    }

    // ── renew ─────────────────────────────────────────────────────────────────

    public function testRenewExtendsSubscription(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertTrue($this->sub->renew('user-1', 'pro', $this->future('+2 months')));
        $this->assertSame('active', $this->sub->status('user-1', 'pro'));
    }

    // ── markPastDue ───────────────────────────────────────────────────────────

    public function testMarkPastDueSetsStatus(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertTrue($this->sub->markPastDue('user-1', 'pro'));
        $row = $this->sub->find('user-1', 'pro');
        $this->assertSame('past_due', $row['status']);
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusReturnsNullForUnknown(): void
    {
        $this->assertNull($this->sub->status('nobody', 'pro'));
    }

    public function testStatusReturnsExpiredWhenPeriodPassed(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->past());
        $this->assertSame('expired', $this->sub->status('user-1', 'pro'));
    }

    // ── isActive ──────────────────────────────────────────────────────────────

    public function testIsActiveReturnsTrueForActiveSubscription(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertTrue($this->sub->isActive('user-1'));
    }

    public function testIsActiveReturnsFalseWhenNoSubscription(): void
    {
        $this->assertFalse($this->sub->isActive('nobody'));
    }

    public function testIsActiveReturnsFalseWhenExpired(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->past());
        $this->assertFalse($this->sub->isActive('user-1'));
    }

    public function testIsActiveReturnsFalseWhenCancelled(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->sub->cancel('user-1', 'pro');
        $this->assertFalse($this->sub->isActive('user-1'));
    }

    // ── isSubscribed ──────────────────────────────────────────────────────────

    public function testIsSubscribedReturnsTrueForActivePlan(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertTrue($this->sub->isSubscribed('user-1', 'pro'));
    }

    public function testIsSubscribedReturnsFalseForDifferentPlan(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->assertFalse($this->sub->isSubscribed('user-1', 'enterprise'));
    }

    // ── listForUser ───────────────────────────────────────────────────────────

    public function testListForUserReturnsAllPlans(): void
    {
        $this->sub->subscribe('user-1', 'pro', $this->future());
        $this->sub->subscribe('user-1', 'addon', $this->future());
        $list = $this->sub->listForUser('user-1');
        $this->assertCount(2, $list);
    }

    public function testListForUserReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->sub->listForUser('nobody'));
    }
}
