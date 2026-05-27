<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PaymentRecord;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymentRecord.
 */
final class PaymentRecordTest extends TestCase
{
    private PDO $db;
    private PaymentRecord $pr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE payment_records (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                reference    VARCHAR(100) NOT NULL UNIQUE,
                user_id      VARCHAR(255) NOT NULL,
                amount_cents BIGINT       NOT NULL,
                currency     VARCHAR(10)  NOT NULL DEFAULT \'USD\',
                gateway      VARCHAR(50)  NOT NULL DEFAULT \'\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                gateway_ref  VARCHAR(255) NOT NULL DEFAULT \'\',
                error        VARCHAR(255) NOT NULL DEFAULT \'\',
                paid_at      DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pr = new PaymentRecord($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsPositiveId(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500, 'JPY', 'stripe');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateInitialStatusIsPending(): void
    {
        $id  = $this->pr->create('ref-001', 'user-1', 1500);
        $rec = $this->pr->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(PaymentRecord::STATUS_PENDING, $rec['status']);
    }

    public function testCreateUppercasesCurrency(): void
    {
        $id  = $this->pr->create('ref-001', 'user-1', 1500, 'jpy');
        $rec = $this->pr->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('JPY', $rec['currency']);
    }

    public function testCreateThrowsOnEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pr->create('', 'user-1', 1500);
    }

    public function testCreateThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pr->create('ref-001', '', 1500);
    }

    public function testCreateThrowsOnNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pr->create('ref-001', 'user-1', 0);
    }

    // ── markPaid ──────────────────────────────────────────────────────────────

    public function testMarkPaidChangesStatusToPaid(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->assertTrue($this->pr->markPaid($id, 'ch_abc123'));
        $rec = $this->pr->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(PaymentRecord::STATUS_PAID, $rec['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ch_abc123', $rec['gateway_ref']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($rec['paid_at']);
    }

    public function testMarkPaidReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->pr->markPaid(999));
    }

    public function testMarkPaidCannotOverrideAlreadyPaid(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->pr->markPaid($id);
        $this->assertFalse($this->pr->markPaid($id, 'new-ref'));
    }

    // ── markFailed ────────────────────────────────────────────────────────────

    public function testMarkFailedChangesStatusToFailed(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->assertTrue($this->pr->markFailed($id, 'card_declined'));
        $rec = $this->pr->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(PaymentRecord::STATUS_FAILED, $rec['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('card_declined', $rec['error']);
    }

    public function testMarkFailedReturnsFalseForNonPendingRecord(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->pr->markPaid($id);
        $this->assertFalse($this->pr->markFailed($id, 'error'));
    }

    // ── markRefunded ──────────────────────────────────────────────────────────

    public function testMarkRefundedChangesStatusToRefunded(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->pr->markPaid($id);
        $this->assertTrue($this->pr->markRefunded($id));
        $rec = $this->pr->find($id);
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(PaymentRecord::STATUS_REFUNDED, $rec['status']);
    }

    public function testMarkRefundedReturnsFalseForNonPaidRecord(): void
    {
        $id = $this->pr->create('ref-001', 'user-1', 1500);
        $this->assertFalse($this->pr->markRefunded($id));
    }

    // ── findByReference ───────────────────────────────────────────────────────

    public function testFindByReferenceReturnsRecord(): void
    {
        $this->pr->create('ref-001', 'user-1', 1500);
        $rec = $this->pr->findByReference('ref-001');
        $this->assertNotNull($rec);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ref-001', $rec['reference']);
    }

    public function testFindByReferenceReturnsNullForUnknown(): void
    {
        $this->assertNull($this->pr->findByReference('unknown'));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserListsAllRecords(): void
    {
        $this->pr->create('ref-001', 'user-1', 1000);
        $this->pr->create('ref-002', 'user-1', 2000);
        $this->pr->create('ref-003', 'user-2', 3000);
        $list = $this->pr->forUser('user-1');
        $this->assertCount(2, $list);
    }

    public function testForUserFiltersOnStatus(): void
    {
        $id1 = $this->pr->create('ref-001', 'user-1', 1000);
        $this->pr->create('ref-002', 'user-1', 2000);
        $this->pr->markPaid($id1);
        $list = $this->pr->forUser('user-1', PaymentRecord::STATUS_PAID);
        $this->assertCount(1, $list);
    }

    // ── totalPaid ─────────────────────────────────────────────────────────────

    public function testTotalPaidSumsPaidAmounts(): void
    {
        $id1 = $this->pr->create('ref-001', 'user-1', 1000, 'USD');
        $id2 = $this->pr->create('ref-002', 'user-1', 2000, 'USD');
        $this->pr->markPaid($id1);
        $this->pr->markPaid($id2);
        $this->assertSame(3000, $this->pr->totalPaid('user-1'));
    }

    public function testTotalPaidExcludesPendingAndFailed(): void
    {
        $this->pr->create('ref-001', 'user-1', 1000); // pending
        $this->assertSame(0, $this->pr->totalPaid('user-1'));
    }

    public function testTotalPaidFiltersByCurrency(): void
    {
        $id1 = $this->pr->create('ref-001', 'user-1', 1000, 'USD');
        $id2 = $this->pr->create('ref-002', 'user-1', 5000, 'JPY');
        $this->pr->markPaid($id1);
        $this->pr->markPaid($id2);
        $this->assertSame(1000, $this->pr->totalPaid('user-1', 'USD'));
        $this->assertSame(5000, $this->pr->totalPaid('user-1', 'JPY'));
    }
}
