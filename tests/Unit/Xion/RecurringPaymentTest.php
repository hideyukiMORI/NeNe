<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\RecurringPayment;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecurringPaymentTest extends TestCase
{
    private PDO $pdo;
    private RecurringPayment $rp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE recurring_payments (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id           VARCHAR(255) NOT NULL,
                amount_cents      INT          NOT NULL,
                currency          CHAR(3)      NOT NULL DEFAULT \'USD\',
                frequency         VARCHAR(20)  NOT NULL,
                next_billing_date DATE         NOT NULL,
                last_billed_date  DATE         NULL,
                active            TINYINT(1)   NOT NULL DEFAULT 1,
                created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->rp = new RecurringPayment($this->pdo);
    }

    // ── schedule ──────────────────────────────────────────────────────────────

    public function testScheduleReturnsId(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $this->assertGreaterThan(0, $id);
    }

    public function testScheduleStoresFields(): void
    {
        $id  = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $row = $this->rp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(999, (int)$row['amount_cents']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('USD', $row['currency']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('monthly', $row['frequency']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-01', $row['next_billing_date']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    public function testScheduleThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rp->schedule('', 999, 'USD', 'monthly', '2026-06-01');
    }

    public function testScheduleThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rp->schedule('user-1', 0, 'USD', 'monthly', '2026-06-01');
    }

    public function testScheduleThrowsOnInvalidFrequency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rp->schedule('user-1', 999, 'USD', 'hourly', '2026-06-01');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->rp->get(9999));
    }

    // ── due ───────────────────────────────────────────────────────────────────

    public function testDueReturnsDueSchedules(): void
    {
        $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-05-01');
        $this->rp->schedule('user-2', 499, 'USD', 'monthly', '2026-07-01');
        $due = $this->rp->due('2026-06-01');
        $this->assertCount(1, $due);
        $this->assertSame('user-1', $due[0]['user_id']);
    }

    public function testDueExcludesPausedSchedules(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-05-01');
        $this->rp->pause($id);
        $this->assertCount(0, $this->rp->due('2026-06-01'));
    }

    public function testDueReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->rp->due('2026-01-01'));
    }

    // ── recordBilling ─────────────────────────────────────────────────────────

    public function testRecordBillingAdvancesMonthly(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $this->rp->recordBilling($id, '2026-06-01');
        $row = $this->rp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-07-01', $row['next_billing_date']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-01', $row['last_billed_date']);
    }

    public function testRecordBillingAdvancesWeekly(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'weekly', '2026-06-01');
        $this->rp->recordBilling($id, '2026-06-01');
        $row = $this->rp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-08', $row['next_billing_date']);
    }

    public function testRecordBillingReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rp->recordBilling(9999));
    }

    // ── pause / resume ────────────────────────────────────────────────────────

    public function testPauseAndResumeToggleActive(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $this->assertTrue($this->rp->pause($id));
        $row = $this->rp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['active']);
        $this->rp->resume($id);
        $row = $this->rp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelDeletesSchedule(): void
    {
        $id = $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $this->assertTrue($this->rp->cancel($id));
        $this->assertNull($this->rp->get($id));
    }

    public function testCancelReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rp->cancel(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllSchedules(): void
    {
        $this->rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
        $this->rp->schedule('user-1', 299, 'USD', 'yearly', '2026-01-01');
        $this->rp->schedule('user-2', 499, 'USD', 'weekly', '2026-06-01');
        $this->assertCount(2, $this->rp->forUser('user-1'));
        $this->assertCount(1, $this->rp->forUser('user-2'));
    }

    public function testForUserReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->rp->forUser('unknown'));
    }
}
