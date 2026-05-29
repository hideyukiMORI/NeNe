<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SpaceOccupancy;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SpaceOccupancy.
 */
final class SpaceOccupancyTest extends TestCase
{
    private PDO $db;
    private SpaceOccupancy $o;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE space_occupancy (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                space    VARCHAR(150) NOT NULL,
                capacity INTEGER      NOT NULL,
                current  INTEGER      NOT NULL DEFAULT 0,
                peak     INTEGER      NOT NULL DEFAULT 0,
                UNIQUE (space)
            )
        ');
        $this->o = new SpaceOccupancy($this->db);
    }

    public function testEnterUntilFull(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->assertTrue($this->o->enter('gym'));      // 1
        $this->assertTrue($this->o->enter('gym', 49));  // 50
        $this->assertSame(50, $this->o->current('gym'));
        $this->assertTrue($this->o->isFull('gym'));
        $this->assertFalse($this->o->enter('gym'));     // would overflow
        $this->assertSame(50, $this->o->current('gym')); // unchanged
    }

    public function testEnterRejectsPartialOverflow(): void
    {
        $this->o->defineSpace('room', 10);
        $this->o->enter('room', 8);
        $this->assertFalse($this->o->enter('room', 3)); // 8+3 > 10, all-or-nothing
        $this->assertSame(8, $this->o->current('room'));
        $this->assertTrue($this->o->enter('room', 2));  // exactly fills
        $this->assertSame(10, $this->o->current('room'));
    }

    public function testLeaveAndAvailable(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->o->enter('gym', 30);
        $this->assertTrue($this->o->leave('gym', 10));
        $this->assertSame(20, $this->o->current('gym'));
        $this->assertSame(30, $this->o->available('gym'));
        $this->assertFalse($this->o->isFull('gym'));
    }

    public function testLeaveNeverGoesNegative(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->o->enter('gym', 3);
        $this->o->leave('gym', 10); // floor at 0
        $this->assertSame(0, $this->o->current('gym'));
    }

    public function testPeakIsHighWaterMark(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->o->enter('gym', 40);
        $this->o->leave('gym', 35);
        $this->o->enter('gym', 5);
        $this->assertSame(10, $this->o->current('gym'));
        $this->assertSame(40, $this->o->peak('gym')); // peak retained
    }

    public function testResetClearsCountButKeepsPeak(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->o->enter('gym', 30);
        $this->assertTrue($this->o->reset('gym'));
        $this->assertSame(0, $this->o->current('gym'));
        $this->assertSame(30, $this->o->peak('gym'));
    }

    public function testDefineSpaceUpdatesCapacityKeepsCount(): void
    {
        $id1 = $this->o->defineSpace('gym', 50);
        $this->o->enter('gym', 20);
        $id2 = $this->o->defineSpace('gym', 30); // shrink capacity
        $this->assertSame($id1, $id2);
        $this->assertSame(20, $this->o->current('gym')); // count preserved
        $this->assertSame(10, $this->o->available('gym'));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM space_occupancy')->fetchColumn());
    }

    public function testSpacesAreSeparate(): void
    {
        $this->o->defineSpace('a', 10);
        $this->o->defineSpace('b', 10);
        $this->o->enter('a', 10);
        $this->assertTrue($this->o->isFull('a'));
        $this->assertFalse($this->o->isFull('b'));
        $this->assertSame(0, $this->o->current('b'));
    }

    public function testUnknownSpaceReadsAreSafe(): void
    {
        $this->assertSame(0, $this->o->current('ghost'));
        $this->assertSame(0, $this->o->available('ghost'));
        $this->assertSame(0, $this->o->peak('ghost'));
        $this->assertFalse($this->o->isFull('ghost'));
        $this->assertFalse($this->o->leave('ghost'));
        $this->assertFalse($this->o->reset('ghost'));
    }

    public function testEnterUnknownSpaceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->o->enter('ghost');
    }

    public function testDefineSpaceRejectsZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->o->defineSpace('gym', 0);
    }

    public function testEnterRejectsZeroCount(): void
    {
        $this->o->defineSpace('gym', 50);
        $this->expectException(\InvalidArgumentException::class);
        $this->o->enter('gym', 0);
    }
}
