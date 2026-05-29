<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SeatMap;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SeatMap.
 */
final class SeatMapTest extends TestCase
{
    private PDO $db;
    private SeatMap $m;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE seat_map (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                venue  VARCHAR(150) NOT NULL,
                seat   VARCHAR(50)  NOT NULL,
                holder VARCHAR(190) NULL,
                UNIQUE (venue, seat)
            )
        ');
        $this->m = new SeatMap($this->db);
        $this->m->addRow('hall', 'A', 5); // A1..A5
    }

    public function testReserveAndHolder(): void
    {
        $this->assertTrue($this->m->reserve('hall', 'A1', 'alice'));
        $this->assertSame('alice', $this->m->holderOf('hall', 'A1'));
        $this->assertFalse($this->m->isAvailable('hall', 'A1'));
    }

    public function testCannotDoubleReserve(): void
    {
        $this->assertTrue($this->m->reserve('hall', 'A1', 'alice'));
        $this->assertFalse($this->m->reserve('hall', 'A1', 'bob')); // taken
        $this->assertSame('alice', $this->m->holderOf('hall', 'A1')); // unchanged
    }

    public function testRelease(): void
    {
        $this->m->reserve('hall', 'A1', 'alice');
        $this->assertTrue($this->m->release('hall', 'A1'));
        $this->assertNull($this->m->holderOf('hall', 'A1'));
        $this->assertTrue($this->m->isAvailable('hall', 'A1'));
        $this->assertFalse($this->m->release('hall', 'A1')); // already free
        $this->assertTrue($this->m->reserve('hall', 'A1', 'bob')); // re-reservable
    }

    public function testAvailableSeats(): void
    {
        $this->m->reserve('hall', 'A2', 'alice');
        $this->m->reserve('hall', 'A4', 'bob');
        $this->assertSame(['A1', 'A3', 'A5'], $this->m->availableSeats('hall'));
    }

    public function testReservedSeats(): void
    {
        $this->m->reserve('hall', 'A3', 'alice');
        $this->m->reserve('hall', 'A1', 'bob');
        $reserved = $this->m->reservedSeats('hall');
        $this->assertSame('A1', $reserved[0]['seat']);
        $this->assertSame('bob', $reserved[0]['holder']);
        $this->assertSame('A3', $reserved[1]['seat']);
    }

    public function testSeatsOfHolder(): void
    {
        $this->m->reserve('hall', 'A1', 'alice');
        $this->m->reserve('hall', 'A3', 'alice');
        $this->m->reserve('hall', 'A2', 'bob');
        $this->assertSame(['A1', 'A3'], $this->m->seatsOf('hall', 'alice'));
    }

    public function testAddSeatIsIdempotentAndKeepsHolder(): void
    {
        $this->m->reserve('hall', 'A1', 'alice');
        $this->m->addSeat('hall', 'A1'); // re-add must not wipe holder
        $this->assertSame('alice', $this->m->holderOf('hall', 'A1'));
        $this->assertSame(5, (int)$this->db->query('SELECT COUNT(*) FROM seat_map')->fetchColumn());
    }

    public function testVenuesAreSeparate(): void
    {
        $this->m->addRow('annex', 'A', 3);
        $this->m->reserve('hall', 'A1', 'alice');
        $this->assertTrue($this->m->isAvailable('annex', 'A1')); // different venue
        $this->assertCount(3, $this->m->availableSeats('annex'));
    }

    public function testReserveNonexistentSeatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->reserve('hall', 'Z9', 'alice');
    }

    public function testHolderOfUnknownIsNull(): void
    {
        $this->assertNull($this->m->holderOf('hall', 'Z9'));
        $this->assertFalse($this->m->isAvailable('hall', 'Z9')); // nonexistent != available
    }

    public function testAddRowRejectsZeroCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->addRow('hall', 'B', 0);
    }

    public function testReserveRejectsEmptyHolder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->reserve('hall', 'A1', '  ');
    }
}
