<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Payout;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Payout.
 */
final class PayoutTest extends TestCase
{
    private PDO $db;
    private Payout $p;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE payouts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                payee        VARCHAR(190) NOT NULL,
                amount_cents INTEGER      NOT NULL,
                status       VARCHAR(10)  NOT NULL DEFAULT \'pending\',
                reference    VARCHAR(190) NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at      DATETIME     NULL
            )
        ');
        $this->p = new Payout($this->db);
    }

    public function testAccrueAndPendingTotal(): void
    {
        $this->p->accrue('s7', 1500, 'order-100');
        $this->p->accrue('s7', 800, 'order-101');
        $this->assertSame(2300, $this->p->pendingTotal('s7'));
    }

    public function testPaySettlesPending(): void
    {
        $this->p->accrue('s7', 1500);
        $this->p->accrue('s7', 800);
        $settled = $this->p->pay('s7');
        $this->assertSame(2300, $settled);
        $this->assertSame(0, $this->p->pendingTotal('s7'));
        $this->assertSame(2300, $this->p->paidTotal('s7'));
    }

    public function testPayWithNothingPendingReturnsZero(): void
    {
        $this->assertSame(0, $this->p->pay('nobody'));
    }

    public function testAccrueAfterPayStartsFreshPending(): void
    {
        $this->p->accrue('s7', 1000);
        $this->p->pay('s7');
        $this->p->accrue('s7', 500); // new pending after settlement
        $this->assertSame(500, $this->p->pendingTotal('s7'));
        $this->assertSame(1000, $this->p->paidTotal('s7'));
    }

    public function testMarkFailedExcludesFromPending(): void
    {
        $id = $this->p->accrue('s7', 1500);
        $this->p->accrue('s7', 800);
        $this->assertTrue($this->p->markFailed($id));
        $this->assertSame(800, $this->p->pendingTotal('s7')); // failed line excluded
    }

    public function testMarkFailedOnlyAffectsPending(): void
    {
        $id = $this->p->accrue('s7', 1000);
        $this->p->pay('s7'); // now paid
        $this->assertFalse($this->p->markFailed($id)); // can't fail a paid line
    }

    public function testPayDoesNotSettleFailedLines(): void
    {
        $id = $this->p->accrue('s7', 1500);
        $this->p->accrue('s7', 800);
        $this->p->markFailed($id);
        $this->assertSame(800, $this->p->pay('s7')); // only the pending 800
    }

    public function testItemsAllAndFiltered(): void
    {
        $this->p->accrue('s7', 1500, 'a');
        $id = $this->p->accrue('s7', 800, 'b');
        $this->p->markFailed($id);
        $this->assertCount(2, $this->p->items('s7'));
        $this->assertCount(1, $this->p->items('s7', Payout::STATUS_PENDING));
        $this->assertCount(1, $this->p->items('s7', Payout::STATUS_FAILED));
    }

    public function testPayeesAreSeparate(): void
    {
        $this->p->accrue('s7', 1000);
        $this->p->accrue('s8', 500);
        $this->assertSame(1000, $this->p->pendingTotal('s7'));
        $this->assertSame(500, $this->p->pendingTotal('s8'));
    }

    public function testAccrueRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->accrue('s7', 0);
    }

    public function testAccrueRejectsEmptyPayee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->accrue('  ', 1000);
    }

    public function testItemsRejectsUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->items('s7', 'bogus');
    }
}
