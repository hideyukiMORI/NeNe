<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ExpenseClaim;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExpenseClaim.
 */
final class ExpenseClaimTest extends TestCase
{
    private PDO $db;
    private ExpenseClaim $ec;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE expense_claims (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                claimant   VARCHAR(190) NOT NULL,
                status     VARCHAR(10)  NOT NULL DEFAULT \'draft\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE expense_claim_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                claim_id     BIGINT       NOT NULL,
                description  VARCHAR(255) NOT NULL DEFAULT \'\',
                amount_cents INTEGER      NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ec = new ExpenseClaim($this->db);
    }

    public function testCreateDraftAndAddItems(): void
    {
        $id = $this->ec->create('emp');
        $this->assertSame('draft', $this->ec->status($id));
        $this->ec->addItem($id, 'Taxi', 2400);
        $this->ec->addItem($id, 'Lunch', 1800);
        $this->assertSame(4200, $this->ec->total($id));
        $this->assertCount(2, $this->ec->items($id));
    }

    public function testFullLifecycle(): void
    {
        $id = $this->ec->create('emp');
        $this->ec->addItem($id, 'Taxi', 2400);
        $this->assertTrue($this->ec->submit($id));
        $this->assertSame('submitted', $this->ec->status($id));
        $this->assertTrue($this->ec->approve($id));
        $this->assertSame('approved', $this->ec->status($id));
        $this->assertTrue($this->ec->markPaid($id));
        $this->assertSame('paid', $this->ec->status($id));
    }

    public function testReject(): void
    {
        $id = $this->ec->create('emp');
        $this->ec->addItem($id, 'x', 100);
        $this->ec->submit($id);
        $this->assertTrue($this->ec->reject($id));
        $this->assertSame('rejected', $this->ec->status($id));
    }

    public function testCannotAddItemAfterSubmit(): void
    {
        $id = $this->ec->create('emp');
        $this->ec->addItem($id, 'x', 100);
        $this->ec->submit($id);
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->addItem($id, 'late', 50);
    }

    public function testSubmitEmptyClaimThrows(): void
    {
        $id = $this->ec->create('emp');
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->submit($id);
    }

    public function testTransitionsGuardStatus(): void
    {
        $id = $this->ec->create('emp');
        $this->ec->addItem($id, 'x', 100);
        $this->assertFalse($this->ec->approve($id)); // still draft, not submitted
        $this->assertFalse($this->ec->markPaid($id));
        $this->ec->submit($id);
        $this->assertFalse($this->ec->markPaid($id)); // submitted, not approved
    }

    public function testCannotApproveTwice(): void
    {
        $id = $this->ec->create('emp');
        $this->ec->addItem($id, 'x', 100);
        $this->ec->submit($id);
        $this->ec->approve($id);
        $this->assertFalse($this->ec->approve($id));
        $this->assertFalse($this->ec->reject($id)); // already approved
    }

    public function testClaimsForWithTotals(): void
    {
        $a = $this->ec->create('emp');
        $this->ec->addItem($a, 'x', 1000);
        $b = $this->ec->create('emp');
        $this->ec->addItem($b, 'y', 500);
        $this->ec->submit($a);
        $claims = $this->ec->claimsFor('emp');
        $this->assertCount(2, $claims);
        $this->assertCount(1, $this->ec->claimsFor('emp', ExpenseClaim::STATUS_SUBMITTED));
        // newest first → b (draft, 500)
        $this->assertSame(500, $claims[0]['total']);
    }

    public function testClaimantsAreSeparate(): void
    {
        $this->ec->create('e1');
        $this->ec->create('e2');
        $this->assertCount(1, $this->ec->claimsFor('e1'));
    }

    public function testStatusUnknownIsNull(): void
    {
        $this->assertNull($this->ec->status(999));
        $this->assertFalse($this->ec->submit(999));
    }

    public function testAddItemRejectsNonPositive(): void
    {
        $id = $this->ec->create('emp');
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->addItem($id, 'x', 0);
    }

    public function testCreateRejectsEmptyClaimant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->create('  ');
    }
}
