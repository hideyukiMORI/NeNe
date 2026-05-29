<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CreditNote;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditNoteTest extends TestCase
{
    private PDO $pdo;
    private CreditNote $cn;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE credit_notes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                reference   VARCHAR(100) NOT NULL DEFAULT \'\',
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                amount      INTEGER      NOT NULL,
                reason      TEXT         NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                issued_at   DATETIME     NOT NULL,
                applied_at  DATETIME     NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cn = new CreditNote($this->pdo);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturnsId(): void
    {
        $id = $this->cn->issue('order', 'ord-1', 1000);
        $this->assertGreaterThan(0, $id);
    }

    public function testIssueStoresFields(): void
    {
        $id  = $this->cn->issue('order', 'ord-1', 1000, 'Damaged item', 'CN-001');
        $row = $this->cn->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order', $row['entity_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ord-1', $row['entity_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1000, (int)$row['amount']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Damaged item', $row['reason']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('CN-001', $row['reference']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(CreditNote::STATUS_PENDING, $row['status']);
    }

    public function testIssueThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cn->issue('', 'ord-1', 1000);
    }

    public function testIssueThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cn->issue('order', '', 1000);
    }

    public function testIssueThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cn->issue('order', 'ord-1', 0);
    }

    public function testIssueThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cn->issue('order', 'ord-1', -500);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->cn->get(9999));
    }

    // ── apply ─────────────────────────────────────────────────────────────────

    public function testApplyChangesStatus(): void
    {
        $id  = $this->cn->issue('order', 'ord-1', 1000);
        $this->assertTrue($this->cn->apply($id));
        $row = $this->cn->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(CreditNote::STATUS_APPLIED, $row['status']);
    }

    public function testApplySetsAppliedAt(): void
    {
        $id  = $this->cn->issue('order', 'ord-1', 1000);
        $this->cn->apply($id);
        $row = $this->cn->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['applied_at']);
    }

    public function testApplyReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cn->apply(9999));
    }

    // ── void ──────────────────────────────────────────────────────────────────

    public function testVoidChangesStatus(): void
    {
        $id  = $this->cn->issue('order', 'ord-1', 1000);
        $this->assertTrue($this->cn->void($id));
        $row = $this->cn->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(CreditNote::STATUS_VOIDED, $row['status']);
    }

    public function testVoidReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cn->void(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsAllStatuses(): void
    {
        $id1 = $this->cn->issue('order', 'ord-1', 1000);
        $id2 = $this->cn->issue('order', 'ord-1', 500);
        $this->cn->apply($id1);
        $list = $this->cn->forEntity('order', 'ord-1');
        $this->assertCount(2, $list);
        unset($id2);
    }

    public function testForEntityIsIsolatedByEntity(): void
    {
        $this->cn->issue('order', 'ord-1', 1000);
        $this->cn->issue('order', 'ord-2', 500);
        $this->assertCount(1, $this->cn->forEntity('order', 'ord-1'));
        $this->assertCount(1, $this->cn->forEntity('order', 'ord-2'));
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->cn->forEntity('order', 'ord-99'));
    }

    // ── total / pendingTotal ──────────────────────────────────────────────────

    public function testTotalSumsPendingAmounts(): void
    {
        $this->cn->issue('order', 'ord-1', 1000);
        $this->cn->issue('order', 'ord-1', 500);
        $this->assertSame(1500, $this->cn->total('order', 'ord-1'));
    }

    public function testTotalExcludesApplied(): void
    {
        $id = $this->cn->issue('order', 'ord-1', 1000);
        $this->cn->issue('order', 'ord-1', 500);
        $this->cn->apply($id);
        $this->assertSame(500, $this->cn->total('order', 'ord-1'));
    }

    public function testTotalCanFilterByStatus(): void
    {
        $id = $this->cn->issue('order', 'ord-1', 1000);
        $this->cn->apply($id);
        $this->assertSame(1000, $this->cn->total('order', 'ord-1', CreditNote::STATUS_APPLIED));
    }

    public function testTotalReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->cn->total('order', 'ord-99'));
    }

    public function testPendingTotalIsSameAsTotal(): void
    {
        $this->cn->issue('order', 'ord-1', 750);
        $this->assertSame(750, $this->cn->pendingTotal('order', 'ord-1'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldNonPending(): void
    {
        $this->pdo->exec(
            "INSERT INTO credit_notes
                 (entity_type, entity_id, amount, status, issued_at, created_at)
             VALUES ('order', 'ord-1', 1000, 'applied', '2020-01-01', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO credit_notes
                 (entity_type, entity_id, amount, status, issued_at, created_at)
             VALUES ('order', 'ord-2', 500, 'voided', '2020-01-01', '2020-01-02 00:00:00')"
        );
        $deleted = $this->cn->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeletePending(): void
    {
        $id = $this->cn->issue('order', 'ord-1', 1000);
        $this->pdo->exec("UPDATE credit_notes SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->cn->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteRecent(): void
    {
        $this->pdo->exec(
            "INSERT INTO credit_notes
                 (entity_type, entity_id, amount, status, issued_at, created_at)
             VALUES ('order', 'ord-1', 1000, 'applied', '2025-01-01', '2025-01-01 00:00:00')"
        );
        $deleted = $this->cn->purgeOlderThan('2020-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
