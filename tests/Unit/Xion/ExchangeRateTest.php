<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ExchangeRate;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExchangeRate.
 */
final class ExchangeRateTest extends TestCase
{
    private PDO $db;
    private ExchangeRate $fx;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE exchange_rates (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                base           CHAR(3)  NOT NULL,
                quote          CHAR(3)  NOT NULL,
                rate           BIGINT   NOT NULL,
                effective_date CHAR(10) NOT NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (base, quote, effective_date)
            )
        ');
        $this->fx = new ExchangeRate($this->db);
    }

    // ── rateAt / latest ─────────────────────────────────────────────────────────

    public function testRateAtPicksMostRecentOnOrBeforeDate(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->fx->setRate('USD', 'AUD', 1_550_000, '2026-02-01');

        $this->assertSame(1_500_000, $this->fx->rateAt('USD', 'AUD', '2026-01-15')); // before 2nd revision
        $this->assertSame(1_550_000, $this->fx->rateAt('USD', 'AUD', '2026-02-01')); // exactly on revision date
        $this->assertSame(1_550_000, $this->fx->rateAt('USD', 'AUD', '2026-03-10')); // after
    }

    public function testRateAtReturnsNullBeforeAnyRate(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-02-01');
        $this->assertNull($this->fx->rateAt('USD', 'AUD', '2026-01-31')); // +1 day before effective
    }

    public function testLatestIgnoresDate(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->fx->setRate('USD', 'AUD', 1_550_000, '2026-02-01');
        $this->assertSame(1_550_000, $this->fx->latest('USD', 'AUD'));
    }

    public function testLatestNullForUnknownPair(): void
    {
        $this->assertNull($this->fx->latest('USD', 'JPY'));
    }

    public function testSetRateIsIdempotentPerDate(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->fx->setRate('USD', 'AUD', 1_600_000, '2026-01-01'); // overwrite same date
        $this->assertSame(1_600_000, $this->fx->rateAt('USD', 'AUD', '2026-01-01'));
        $this->assertCount(1, $this->fx->history('USD', 'AUD'));
    }

    public function testCodesAreNormalisedToUpper(): void
    {
        $this->fx->setRate('usd', 'aud', 1_500_000, '2026-01-01');
        $this->assertSame(1_500_000, $this->fx->latest('USD', 'AUD'));
        $this->assertSame(1_500_000, $this->fx->rateAt('Usd', 'Aud', '2026-01-02'));
    }

    public function testPairsAreDirectional(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->assertSame(1_500_000, $this->fx->latest('USD', 'AUD'));
        $this->assertNull($this->fx->latest('AUD', 'USD')); // reverse not implied
    }

    // ── convertCents ──────────────────────────────────────────────────────────

    public function testConvertCentsWithDate(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01'); // ×1.5
        $this->assertSame(1500, $this->fx->convertCents('USD', 'AUD', 1000, '2026-01-15'));
    }

    public function testConvertCentsUsesLatestWhenDateNull(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->fx->setRate('USD', 'AUD', 1_550_000, '2026-02-01'); // ×1.55
        $this->assertSame(1550, $this->fx->convertCents('USD', 'AUD', 1000));
    }

    public function testConvertCentsRoundsHalfUp(): void
    {
        // ×1.005 on 100 cents = 100.5 → rounds half-up to 101
        $this->fx->setRate('USD', 'AUD', 1_005_000, '2026-01-01');
        $this->assertSame(101, $this->fx->convertCents('USD', 'AUD', 100));
    }

    public function testConvertCentsRoundsDownBelowHalf(): void
    {
        // ×1.004 on 100 cents = 100.4 → rounds to 100
        $this->fx->setRate('USD', 'AUD', 1_004_000, '2026-01-01');
        $this->assertSame(100, $this->fx->convertCents('USD', 'AUD', 100));
    }

    public function testConvertCentsNegativeAmount(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->assertSame(-1500, $this->fx->convertCents('USD', 'AUD', -1000));
    }

    public function testConvertCentsZero(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->assertSame(0, $this->fx->convertCents('USD', 'AUD', 0));
    }

    public function testConvertCentsNullWhenNoRate(): void
    {
        $this->assertNull($this->fx->convertCents('USD', 'AUD', 1000));
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-02-01');
        $this->assertNull($this->fx->convertCents('USD', 'AUD', 1000, '2026-01-01')); // before effective
    }

    // ── history ─────────────────────────────────────────────────────────────────

    public function testHistoryNewestFirst(): void
    {
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
        $this->fx->setRate('USD', 'AUD', 1_550_000, '2026-02-01');
        $hist = $this->fx->history('USD', 'AUD');
        $this->assertSame('2026-02-01', $hist[0]['date']);
        $this->assertSame(1_550_000, $hist[0]['rate']);
        $this->assertSame('2026-01-01', $hist[1]['date']);
    }

    public function testHistoryEmptyForUnknownPair(): void
    {
        $this->assertSame([], $this->fx->history('USD', 'JPY'));
    }

    // ── validation ──────────────────────────────────────────────────────────────

    public function testSetRateRejectsNonPositiveRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fx->setRate('USD', 'AUD', 0, '2026-01-01');
    }

    public function testSetRateRejectsEmptyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fx->setRate('', 'AUD', 1_500_000, '2026-01-01');
    }

    public function testSetRateRejectsBadDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fx->setRate('USD', 'AUD', 1_500_000, '2026-02-30'); // overflow
    }
}
