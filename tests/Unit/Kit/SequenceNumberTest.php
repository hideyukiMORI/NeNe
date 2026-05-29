<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SequenceNumber;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SequenceNumber.
 */
final class SequenceNumberTest extends TestCase
{
    private PDO $db;
    private SequenceNumber $seq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE sequence_numbers (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                scope         VARCHAR(100) NOT NULL,
                current_value BIGINT       NOT NULL DEFAULT 0,
                updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (scope)
            )
        ');
        $this->seq = new SequenceNumber($this->db);
    }

    // ── next ────────────────────────────────────────────────────────────────────

    public function testFirstNextReturnsOne(): void
    {
        $this->assertSame(1, $this->seq->next('invoice'));
    }

    public function testNextIncrementsSequentially(): void
    {
        $this->assertSame(1, $this->seq->next('invoice'));
        $this->assertSame(2, $this->seq->next('invoice'));
        $this->assertSame(3, $this->seq->next('invoice'));
    }

    public function testScopesAreIndependent(): void
    {
        $this->assertSame(1, $this->seq->next('invoice'));
        $this->assertSame(2, $this->seq->next('invoice'));
        $this->assertSame(1, $this->seq->next('order'));
        $this->assertSame(3, $this->seq->next('invoice'));
        $this->assertSame(2, $this->seq->next('order'));
    }

    public function testSequenceIsGaplessAfterManyCalls(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->assertSame($i, $this->seq->next('ticket'));
        }
    }

    public function testNextTrimsScope(): void
    {
        $this->assertSame(1, $this->seq->next('  invoice  '));
        $this->assertSame(2, $this->seq->next('invoice'));
    }

    public function testNextThrowsOnEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seq->next('   ');
    }

    public function testNextReusesAnOpenTransaction(): void
    {
        $this->db->beginTransaction();
        $a = $this->seq->next('invoice');
        $b = $this->seq->next('invoice');
        $this->db->commit();

        $this->assertSame(1, $a);
        $this->assertSame(2, $b);
        // The outer transaction having been committed by the caller, the value
        // is durable and visible to a subsequent peek.
        $this->assertSame(2, $this->seq->peek('invoice'));
    }

    // ── formatted ─────────────────────────────────────────────────────────────

    public function testFormattedWithPrefixAndDefaultPadding(): void
    {
        $this->assertSame('INV-000001', $this->seq->formatted('invoice', 'INV-'));
        $this->assertSame('INV-000002', $this->seq->formatted('invoice', 'INV-'));
    }

    public function testFormattedCustomPadding(): void
    {
        $this->assertSame('0001', $this->seq->formatted('order', '', 4));
    }

    public function testFormattedDoesNotPadWhenValueExceedsWidth(): void
    {
        $this->seq->reset('order', 12344);
        $this->assertSame('A-12345', $this->seq->formatted('order', 'A-', 3));
    }

    public function testFormattedConsumesTheNumber(): void
    {
        $this->seq->formatted('invoice', 'INV-');
        $this->assertSame(1, $this->seq->peek('invoice'));
    }

    public function testFormattedThrowsOnZeroPad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seq->formatted('invoice', 'INV-', 0);
    }

    // ── peek ──────────────────────────────────────────────────────────────────

    public function testPeekReturnsZeroForUnknownScope(): void
    {
        $this->assertSame(0, $this->seq->peek('never-used'));
    }

    public function testPeekDoesNotConsume(): void
    {
        $this->seq->next('invoice');
        $this->assertSame(1, $this->seq->peek('invoice'));
        $this->assertSame(1, $this->seq->peek('invoice'));
        $this->assertSame(2, $this->seq->next('invoice'));
    }

    public function testPeekThrowsOnEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seq->peek('');
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetToZeroRestartsAtOne(): void
    {
        $this->seq->next('invoice');
        $this->seq->next('invoice');
        $this->seq->reset('invoice');
        $this->assertSame(0, $this->seq->peek('invoice'));
        $this->assertSame(1, $this->seq->next('invoice'));
    }

    public function testResetToValueContinuesAfterThatValue(): void
    {
        $this->seq->reset('invoice', 1000);
        $this->assertSame(1000, $this->seq->peek('invoice'));
        $this->assertSame(1001, $this->seq->next('invoice'));
    }

    public function testResetCreatesScopeIfMissing(): void
    {
        $this->seq->reset('fresh', 5);
        $this->assertSame(6, $this->seq->next('fresh'));
    }

    public function testResetThrowsOnNegativeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seq->reset('invoice', -1);
    }

    public function testResetThrowsOnEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seq->reset('  ');
    }
}
