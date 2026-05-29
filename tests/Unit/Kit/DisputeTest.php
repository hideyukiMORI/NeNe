<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Dispute;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Dispute.
 */
final class DisputeTest extends TestCase
{
    private PDO $db;
    private Dispute $d;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE disputes (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                reference    VARCHAR(190) NOT NULL,
                reason       VARCHAR(255) NOT NULL DEFAULT \'\',
                amount_cents INTEGER      NOT NULL DEFAULT 0,
                status       VARCHAR(20)  NOT NULL DEFAULT \'open\',
                evidence     TEXT         NOT NULL DEFAULT \'\',
                opened_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at  DATETIME     NULL
            )
        ');
        $this->d = new Dispute($this->db);
    }

    public function testOpenStartsOpen(): void
    {
        $id = $this->d->open('txn_1', 'not received', 1500);
        $this->assertSame('open', $this->d->status($id));
        $row = $this->d->get($id);
        $this->assertSame(1500, $row['amount_cents']);
        $this->assertSame('txn_1', $row['reference']);
        $this->assertNull($row['resolved_at']);
    }

    public function testFullLifecycle(): void
    {
        $id = $this->d->open('txn_1', 'not received', 1500);
        $this->assertTrue($this->d->review($id));
        $this->assertSame('under_review', $this->d->status($id));
        $this->assertTrue($this->d->resolve($id, won: true));
        $this->assertSame('won', $this->d->status($id));
        $this->assertNotNull($this->d->get($id)['resolved_at']);
    }

    public function testResolveLost(): void
    {
        $id = $this->d->open('txn_1', 'x', 200);
        $this->assertTrue($this->d->resolve($id, won: false)); // direct from open
        $this->assertSame('lost', $this->d->status($id));
    }

    public function testReviewOnlyFromOpen(): void
    {
        $id = $this->d->open('txn_1', 'x', 200);
        $this->d->resolve($id, won: true);
        $this->assertFalse($this->d->review($id)); // already resolved
    }

    public function testCannotResolveTwice(): void
    {
        $id = $this->d->open('txn_1', 'x', 200);
        $this->assertTrue($this->d->resolve($id, won: true));
        $this->assertFalse($this->d->resolve($id, won: false)); // already won
        $this->assertSame('won', $this->d->status($id));
    }

    public function testAddEvidenceAppends(): void
    {
        $id = $this->d->open('txn_1', 'x', 200);
        $this->assertTrue($this->d->addEvidence($id, 'first'));
        $this->assertTrue($this->d->addEvidence($id, 'second'));
        $this->assertSame("first\nsecond", $this->d->get($id)['evidence']);
    }

    public function testCannotAddEvidenceWhenResolved(): void
    {
        $id = $this->d->open('txn_1', 'x', 200);
        $this->d->resolve($id, won: true);
        $this->assertFalse($this->d->addEvidence($id, 'late'));
        $this->assertSame('', $this->d->get($id)['evidence']);
    }

    public function testAmountAtRisk(): void
    {
        $a = $this->d->open('t1', 'x', 1000); // open
        $this->d->open('t2', 'x', 500);       // open
        $c = $this->d->open('t3', 'x', 700);
        $this->d->review($c);                 // under_review — still at risk
        $w = $this->d->open('t4', 'x', 9999);
        $this->d->resolve($w, won: true);     // resolved — not at risk
        $this->assertSame(2200, $this->d->amountAtRisk()); // 1000 + 500 + 700
        $this->d->resolve($a, won: false);
        $this->assertSame(1200, $this->d->amountAtRisk()); // 500 + 700
    }

    public function testByStatus(): void
    {
        $this->d->open('t1', 'x', 100);
        $b = $this->d->open('t2', 'x', 100);
        $this->d->review($b);
        $this->assertCount(1, $this->d->byStatus('open'));
        $this->assertCount(1, $this->d->byStatus('under_review'));
        $this->assertCount(0, $this->d->byStatus('won'));
    }

    public function testStatusOfMissingIsNull(): void
    {
        $this->assertNull($this->d->status(999));
        $this->assertNull($this->d->get(999));
    }

    public function testOpenRejectsEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->d->open('  ', 'x', 100);
    }

    public function testOpenRejectsNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->d->open('t1', 'x', -1);
    }

    public function testAddEvidenceRejectsEmpty(): void
    {
        $id = $this->d->open('t1', 'x', 100);
        $this->expectException(\InvalidArgumentException::class);
        $this->d->addEvidence($id, '   ');
    }
}
