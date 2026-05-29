<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UtmCampaign;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UtmCampaign.
 */
final class UtmCampaignTest extends TestCase
{
    private PDO $db;
    private UtmCampaign $utm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE utm_touches (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor   VARCHAR(190) NOT NULL,
                source    VARCHAR(150) NOT NULL,
                medium    VARCHAR(150) NOT NULL DEFAULT \'\',
                campaign  VARCHAR(150) NOT NULL DEFAULT \'\',
                term      VARCHAR(150) NOT NULL DEFAULT \'\',
                content   VARCHAR(150) NOT NULL DEFAULT \'\',
                landed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->utm = new UtmCampaign($this->db);
    }

    public function testRecordAndTouches(): void
    {
        $this->utm->record('v1', ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'spring']);
        $touches = $this->utm->touchesFor('v1');
        $this->assertCount(1, $touches);
        $this->assertSame('google', $touches[0]['source']);
        $this->assertSame('cpc', $touches[0]['medium']);
        $this->assertSame('spring', $touches[0]['campaign']);
    }

    public function testFirstAndLastTouch(): void
    {
        $this->utm->record('v1', ['source' => 'google', 'medium' => 'cpc']);
        $this->utm->record('v1', ['source' => 'newsletter', 'medium' => 'email']);
        $this->assertSame('google', $this->utm->firstTouch('v1')['source']);
        $this->assertSame('newsletter', $this->utm->lastTouch('v1')['source']);
    }

    public function testEdgeTouchesNullWhenNone(): void
    {
        $this->assertNull($this->utm->firstTouch('nobody'));
        $this->assertNull($this->utm->lastTouch('nobody'));
    }

    public function testTouchesOldestFirst(): void
    {
        $this->utm->record('v1', ['source' => 'a']);
        $this->utm->record('v1', ['source' => 'b']);
        $this->utm->record('v1', ['source' => 'c']);
        $sources = array_map(static fn (array $t): string => $t['source'], $this->utm->touchesFor('v1'));
        $this->assertSame(['a', 'b', 'c'], $sources);
    }

    public function testOptionalFieldsDefaultEmpty(): void
    {
        $this->utm->record('v1', ['source' => 'direct']);
        $t = $this->utm->firstTouch('v1');
        $this->assertSame('', $t['medium']);
        $this->assertSame('', $t['campaign']);
    }

    public function testCampaignTouches(): void
    {
        $this->utm->record('v1', ['source' => 'google', 'campaign' => 'spring']);
        $this->utm->record('v2', ['source' => 'fb', 'campaign' => 'spring']);
        $this->utm->record('v3', ['source' => 'fb', 'campaign' => 'summer']);
        $this->assertSame(2, $this->utm->campaignTouches('spring'));
    }

    public function testCountBy(): void
    {
        $this->utm->record('v1', ['source' => 'google']);
        $this->utm->record('v2', ['source' => 'google']);
        $this->utm->record('v3', ['source' => 'fb']);
        $counts = $this->utm->countBy('source');
        $this->assertSame(2, $counts['google']);
        $this->assertSame(1, $counts['fb']);
        // busiest first
        $this->assertSame('google', array_key_first($counts));
    }

    public function testCountByRejectsUnknownField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->utm->countBy('evil; DROP TABLE');
    }

    public function testVisitorsAreSeparate(): void
    {
        $this->utm->record('v1', ['source' => 'a']);
        $this->utm->record('v2', ['source' => 'b']);
        $this->assertCount(1, $this->utm->touchesFor('v1'));
        $this->assertCount(1, $this->utm->touchesFor('v2'));
    }

    public function testPurgeOlderThan(): void
    {
        $this->utm->record('v1', ['source' => 'old'], '2026-01-01 00:00:00');
        $this->utm->record('v1', ['source' => 'new'], '2026-05-29 00:00:00');
        $removed = $this->utm->purgeOlderThan(90, '2026-05-29 00:00:00');
        $this->assertSame(1, $removed);
        $this->assertCount(1, $this->utm->touchesFor('v1'));
    }

    public function testRecordRejectsEmptyVisitor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->utm->record('  ', ['source' => 'google']);
    }

    public function testRecordRejectsEmptySource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->utm->record('v1', ['medium' => 'cpc']);
    }
}
