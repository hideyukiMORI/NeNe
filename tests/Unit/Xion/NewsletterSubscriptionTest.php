<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\NewsletterSubscription;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NewsletterSubscription.
 */
final class NewsletterSubscriptionTest extends TestCase
{
    private PDO $db;
    private NewsletterSubscription $nl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE newsletter_subscriptions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                email           VARCHAR(255) NOT NULL,
                list_name       VARCHAR(100) NOT NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                confirm_hash    VARCHAR(64)  DEFAULT NULL,
                confirmed_at    DATETIME     DEFAULT NULL,
                unsubscribed_at DATETIME     DEFAULT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (email, list_name)
            )
        ');
        $this->nl = new NewsletterSubscription($this->db);
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    public function testSubscribeReturnsToken(): void
    {
        $token = $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertIsString($token);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testSubscribeStatusIsPending(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertSame('pending', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testSubscribeWithConfirmTrueIsConfirmedImmediately(): void
    {
        $token = $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $this->assertNull($token);
        $this->assertSame('confirmed', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testSubscribeAlreadyConfirmedIsNoOp(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $token = $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertNull($token); // already confirmed — not re-queued
        $this->assertSame('confirmed', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testSubscribeResetsUnsubscribed(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $this->nl->unsubscribe('user@example.com', 'weekly');
        $token = $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertIsString($token);
        $this->assertSame('pending', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testSubscribeNormalisesEmailCase(): void
    {
        $this->nl->subscribe('USER@EXAMPLE.COM', 'weekly');
        $this->assertSame('pending', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testSubscribeThrowsOnInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nl->subscribe('not-an-email', 'weekly');
    }

    public function testSubscribeThrowsOnEmptyListName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nl->subscribe('user@example.com', '');
    }

    // ── confirm ───────────────────────────────────────────────────────────────

    public function testConfirmWithValidTokenReturnsTrue(): void
    {
        $token = $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertNotNull($token);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($this->nl->confirm($token));
        $this->assertSame('confirmed', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testConfirmWithInvalidTokenReturnsFalse(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertFalse($this->nl->confirm('0000000000000000000000000000000000000000000000000000000000000000'));
    }

    public function testConfirmTokenIsOneTime(): void
    {
        $token = $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertNotNull($token);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->nl->confirm($token);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertFalse($this->nl->confirm($token)); // already confirmed → not pending
    }

    // ── unsubscribe ───────────────────────────────────────────────────────────

    public function testUnsubscribeConfirmedSubscriber(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $this->assertTrue($this->nl->unsubscribe('user@example.com', 'weekly'));
        $this->assertSame('unsubscribed', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testUnsubscribePendingSubscriber(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly');
        $this->assertTrue($this->nl->unsubscribe('user@example.com', 'weekly'));
        $this->assertSame('unsubscribed', $this->nl->status('user@example.com', 'weekly'));
    }

    public function testUnsubscribeAlreadyUnsubscribedReturnsFalse(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $this->nl->unsubscribe('user@example.com', 'weekly');
        $this->assertFalse($this->nl->unsubscribe('user@example.com', 'weekly'));
    }

    public function testUnsubscribeNonExistentReturnsFalse(): void
    {
        $this->assertFalse($this->nl->unsubscribe('unknown@example.com', 'weekly'));
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusReturnsNullForUnknown(): void
    {
        $this->assertNull($this->nl->status('nobody@example.com', 'weekly'));
    }

    // ── listConfirmed ─────────────────────────────────────────────────────────

    public function testListConfirmedReturnsOnlyConfirmedEmails(): void
    {
        $this->nl->subscribe('a@example.com', 'weekly', confirm: true);
        $this->nl->subscribe('b@example.com', 'weekly'); // pending
        $this->nl->subscribe('c@example.com', 'weekly', confirm: true);
        $this->nl->unsubscribe('c@example.com', 'weekly');

        $list = $this->nl->listConfirmed('weekly');
        $this->assertSame(['a@example.com'], $list);
    }

    public function testListConfirmedIsListIsolated(): void
    {
        $this->nl->subscribe('a@example.com', 'weekly', confirm: true);
        $this->nl->subscribe('b@example.com', 'monthly', confirm: true);

        $this->assertSame(['a@example.com'], $this->nl->listConfirmed('weekly'));
        $this->assertSame(['b@example.com'], $this->nl->listConfirmed('monthly'));
    }

    // ── resubscribe ───────────────────────────────────────────────────────────

    public function testResubscribeAfterUnsubscribeReturnsPending(): void
    {
        $this->nl->subscribe('user@example.com', 'weekly', confirm: true);
        $this->nl->unsubscribe('user@example.com', 'weekly');
        $token = $this->nl->resubscribe('user@example.com', 'weekly');
        $this->assertIsString($token);
        $this->assertSame('pending', $this->nl->status('user@example.com', 'weekly'));
    }
}
