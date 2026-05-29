<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AbTest;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbTest.
 */
final class AbTestTest extends TestCase
{
    private PDO $db;
    private AbTest $ab;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE ab_experiments (
                experiment VARCHAR(100) NOT NULL PRIMARY KEY,
                variants   TEXT         NOT NULL DEFAULT \'[]\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE ab_assignments (
                experiment  VARCHAR(100) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                variant     VARCHAR(100) NOT NULL,
                assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (experiment, user_id)
            )
        ');
        $this->db->exec('
            CREATE TABLE ab_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                experiment VARCHAR(100) NOT NULL,
                variant    VARCHAR(100) NOT NULL,
                event_type VARCHAR(20)  NOT NULL,
                user_id    VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ab = new AbTest($this->db);
    }

    // ── define ────────────────────────────────────────────────────────────────

    public function testDefineCreatesExperiment(): void
    {
        $this->ab->define('btn-color', ['control', 'green']);
        $variant = $this->ab->assign('btn-color', 'user-1');
        $this->assertContains($variant, ['control', 'green']);
    }

    public function testDefineIsUpsert(): void
    {
        $this->ab->define('btn-color', ['control', 'green']);
        $this->ab->define('btn-color', ['control', 'blue']); // update
        // Should not throw
        $variant = $this->ab->assign('btn-color', 'new-user');
        $this->assertContains($variant, ['control', 'blue']);
    }

    public function testDefineThrowsOnEmptyExperiment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ab->define('', ['a', 'b']);
    }

    public function testDefineThrowsOnEmptyVariants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ab->define('exp', []);
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function testAssignReturnsAVariant(): void
    {
        $this->ab->define('exp', ['a', 'b', 'c']);
        $variant = $this->ab->assign('exp', 'user-1');
        $this->assertContains($variant, ['a', 'b', 'c']);
    }

    public function testAssignIsIdempotent(): void
    {
        $this->ab->define('exp', ['a', 'b']);
        $v1 = $this->ab->assign('exp', 'user-1');
        $v2 = $this->ab->assign('exp', 'user-1');
        $this->assertSame($v1, $v2);
    }

    public function testAssignThrowsForUndefinedExperiment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ab->assign('undefined-exp', 'user-1');
    }

    public function testAssignDistributesBetweenVariants(): void
    {
        $this->ab->define('exp', ['control', 'variant']);
        $assigned = [];
        for ($i = 0; $i < 50; $i++) {
            $assigned[] = $this->ab->assign('exp', "user-{$i}");
        }
        $this->assertContains('control', $assigned);
        $this->assertContains('variant', $assigned);
    }

    // ── getVariant ────────────────────────────────────────────────────────────

    public function testGetVariantReturnsNullBeforeAssignment(): void
    {
        $this->assertNull($this->ab->getVariant('exp', 'user-1'));
    }

    public function testGetVariantReturnsAssignedVariant(): void
    {
        $this->ab->define('exp', ['a', 'b']);
        $v = $this->ab->assign('exp', 'user-1');
        $this->assertSame($v, $this->ab->getVariant('exp', 'user-1'));
    }

    // ── impression + convert + results ────────────────────────────────────────

    public function testImpressionTracksEvent(): void
    {
        $this->ab->define('cta', ['control', 'bold']);
        $variant = $this->ab->assign('cta', 'user-1');
        $this->ab->impression('cta', 'user-1');

        $results = $this->ab->results('cta');
        $this->assertSame(1, $results[$variant]['impressions']);
    }

    public function testConvertTracksConversion(): void
    {
        $this->ab->define('cta', ['control', 'bold']);
        $variant = $this->ab->assign('cta', 'user-1');
        $this->ab->impression('cta', 'user-1');
        $this->ab->convert('cta', 'user-1');

        $results = $this->ab->results('cta');
        $this->assertSame(1, $results[$variant]['conversions']);
    }

    public function testResultsCalculatesRate(): void
    {
        $this->ab->define('cta', ['v1']);
        $this->ab->assign('cta', 'user-1');
        $this->ab->impression('cta', 'user-1');
        $this->ab->assign('cta', 'user-2');
        $this->ab->impression('cta', 'user-2');
        $this->ab->convert('cta', 'user-1');

        $results = $this->ab->results('cta');
        $this->assertEqualsWithDelta(0.5, $results['v1']['rate'], 0.001);
    }

    public function testImpressionDoesNothingIfNotAssigned(): void
    {
        $this->ab->define('exp', ['a']);
        $this->ab->impression('exp', 'unassigned-user');
        $results = $this->ab->results('exp');
        $this->assertSame(0, $results['a']['impressions']);
    }

    public function testResultsReturnsZerosForVariantsWithNoEvents(): void
    {
        $this->ab->define('exp', ['a', 'b']);
        $results = $this->ab->results('exp');
        $this->assertSame(0, $results['a']['impressions']);
        $this->assertSame(0, $results['b']['conversions']);
    }
}
