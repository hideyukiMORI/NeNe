<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\AffiliateClick;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AffiliateClick.
 */
final class AffiliateClickTest extends TestCase
{
    private PDO $db;
    private AffiliateClick $ac;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE affiliate_clicks (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                affiliate        VARCHAR(100) NOT NULL,
                click_id         VARCHAR(190) NOT NULL,
                landing          VARCHAR(255) NOT NULL DEFAULT \'\',
                converted        INTEGER      NOT NULL DEFAULT 0,
                conversion_value INTEGER      NOT NULL DEFAULT 0,
                clicked_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                converted_at     DATETIME     NULL,
                UNIQUE (click_id)
            )
        ');
        $this->ac = new AffiliateClick($this->db);
    }

    public function testRecordClickAndCount(): void
    {
        $this->ac->recordClick('p7', 'clk1', '/pricing');
        $this->ac->recordClick('p7', 'clk2');
        $this->assertSame(2, $this->ac->clicksFor('p7'));
    }

    public function testRecordClickIdempotentPerClickId(): void
    {
        $this->ac->recordClick('p7', 'clk1');
        $this->ac->recordClick('p7', 'clk1'); // ignored
        $this->assertSame(1, $this->ac->clicksFor('p7'));
    }

    public function testConvert(): void
    {
        $this->ac->recordClick('p7', 'clk1');
        $this->assertTrue($this->ac->convert('clk1', 4990));
        $this->assertTrue($this->ac->isConverted('clk1'));
        $this->assertSame(4990, $this->ac->revenueFor('p7'));
    }

    public function testConvertUnknownClickReturnsFalse(): void
    {
        $this->assertFalse($this->ac->convert('ghost', 100));
    }

    public function testConvertTwiceReturnsFalseSecondTime(): void
    {
        $this->ac->recordClick('p7', 'clk1');
        $this->assertTrue($this->ac->convert('clk1', 1000));
        $this->assertFalse($this->ac->convert('clk1', 2000)); // already converted
        $this->assertSame(1000, $this->ac->revenueFor('p7')); // value unchanged
    }

    public function testConversionsAndRevenue(): void
    {
        $this->ac->recordClick('p7', 'a');
        $this->ac->recordClick('p7', 'b');
        $this->ac->recordClick('p7', 'c');
        $this->ac->convert('a', 1000);
        $this->ac->convert('b', 2000);
        $this->assertSame(2, $this->ac->conversionsFor('p7'));
        $this->assertSame(3000, $this->ac->revenueFor('p7'));
    }

    public function testStats(): void
    {
        $this->ac->recordClick('p7', 'a');
        $this->ac->recordClick('p7', 'b');
        $this->ac->recordClick('p7', 'c');
        $this->ac->recordClick('p7', 'd');
        $this->ac->convert('a', 5000);
        $stats = $this->ac->stats('p7');
        $this->assertSame(4, $stats['clicks']);
        $this->assertSame(1, $stats['conversions']);
        $this->assertSame(5000, $stats['revenue']);
        $this->assertSame(0.25, $stats['rate']);
    }

    public function testStatsZeroClicksRateZero(): void
    {
        $stats = $this->ac->stats('nobody');
        $this->assertSame(0, $stats['clicks']);
        $this->assertSame(0.0, $stats['rate']);
    }

    public function testAffiliatesAreSeparate(): void
    {
        $this->ac->recordClick('p7', 'a');
        $this->ac->recordClick('p8', 'b');
        $this->assertSame(1, $this->ac->clicksFor('p7'));
        $this->assertSame(1, $this->ac->clicksFor('p8'));
    }

    public function testPurgeOlderThan(): void
    {
        $this->ac->recordClick('p7', 'old', '', '2026-01-01 00:00:00');
        $this->ac->recordClick('p7', 'new', '', '2026-05-29 00:00:00');
        $removed = $this->ac->purgeOlderThan(90, '2026-05-29 00:00:00');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->ac->clicksFor('p7'));
    }

    public function testConvertRejectsNegativeValue(): void
    {
        $this->ac->recordClick('p7', 'clk1');
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->convert('clk1', -1);
    }

    public function testRecordClickRejectsEmptyAffiliate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->recordClick('  ', 'clk1');
    }

    public function testRecordClickRejectsEmptyClickId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->recordClick('p7', '  ');
    }
}
