<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Escalation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Escalation.
 */
final class EscalationTest extends TestCase
{
    private PDO $db;
    private Escalation $e;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE escalation_cases (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                reference  VARCHAR(190) NOT NULL,
                level      INTEGER      NOT NULL DEFAULT 1,
                max_level  INTEGER      NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'open\',
                opened_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NULL,
                UNIQUE (reference)
            )
        ');
        $this->e = new Escalation($this->db);
    }

    public function testOpenStartsAtLevelOne(): void
    {
        $this->e->open('t1', 3);
        $this->assertSame(1, $this->e->level('t1'));
        $this->assertFalse($this->e->isResolved('t1'));
        $this->assertFalse($this->e->atMaxLevel('t1'));
    }

    public function testEscalateLadderToCeiling(): void
    {
        $this->e->open('t1', 3);
        $this->assertTrue($this->e->escalate('t1'));  // L2
        $this->assertSame(2, $this->e->level('t1'));
        $this->assertTrue($this->e->escalate('t1'));  // L3
        $this->assertSame(3, $this->e->level('t1'));
        $this->assertTrue($this->e->atMaxLevel('t1'));
        $this->assertFalse($this->e->escalate('t1')); // at ceiling
        $this->assertSame(3, $this->e->level('t1'));  // unchanged
    }

    public function testResolveStopsEscalation(): void
    {
        $this->e->open('t1', 3);
        $this->e->escalate('t1'); // L2
        $this->assertTrue($this->e->resolve('t1'));
        $this->assertTrue($this->e->isResolved('t1'));
        $this->assertFalse($this->e->escalate('t1')); // resolved → no-op
        $this->assertSame(2, $this->e->level('t1'));
        $this->assertFalse($this->e->atMaxLevel('t1')); // resolved, not "at max"
    }

    public function testCannotResolveTwice(): void
    {
        $this->e->open('t1', 3);
        $this->assertTrue($this->e->resolve('t1'));
        $this->assertFalse($this->e->resolve('t1')); // already resolved
    }

    public function testSingleLevelLadderIsImmediatelyAtMax(): void
    {
        $this->e->open('t1', 1);
        $this->assertTrue($this->e->atMaxLevel('t1'));
        $this->assertFalse($this->e->escalate('t1')); // nowhere to go
    }

    public function testActiveCasesOrderedByLevelDesc(): void
    {
        $this->e->open('low', 3);
        $this->e->open('high', 3);
        $this->e->escalate('high');
        $this->e->escalate('high'); // high at L3
        $this->e->open('done', 3);
        $this->e->resolve('done');  // excluded
        $active = $this->e->activeCases();
        $this->assertCount(2, $active);
        $this->assertSame('high', $active[0]['reference']);
        $this->assertSame(3, $active[0]['level']);
        $this->assertSame('low', $active[1]['reference']);
    }

    public function testCountAtLevel(): void
    {
        $this->e->open('a', 3);
        $this->e->open('b', 3);
        $this->e->open('c', 3);
        $this->e->escalate('b'); // b → L2
        $this->assertSame(2, $this->e->countAtLevel(1)); // a, c
        $this->assertSame(1, $this->e->countAtLevel(2)); // b
        $this->e->resolve('a');
        $this->assertSame(1, $this->e->countAtLevel(1)); // only c now
    }

    public function testUnknownReferenceReadsAreSafe(): void
    {
        $this->assertNull($this->e->level('ghost'));
        $this->assertFalse($this->e->isResolved('ghost'));
        $this->assertFalse($this->e->atMaxLevel('ghost'));
        $this->assertFalse($this->e->escalate('ghost'));
        $this->assertFalse($this->e->resolve('ghost'));
    }

    public function testOpenDuplicateThrows(): void
    {
        $this->e->open('t1', 3);
        $this->expectException(\InvalidArgumentException::class);
        $this->e->open('t1', 3);
    }

    public function testOpenRejectsZeroMaxLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->e->open('t1', 0);
    }

    public function testOpenRejectsEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->e->open('   ', 3);
    }
}
