<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TimeSlot;
use PDO;
use PHPUnit\Framework\TestCase;

final class TimeSlotTest extends TestCase
{
    private PDO $pdo;
    private TimeSlot $ts;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE time_slots (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_ref VARCHAR(255) NOT NULL,
                slot_date    DATE         NOT NULL,
                start_time   VARCHAR(5)   NOT NULL,
                end_time     VARCHAR(5)   NOT NULL,
                capacity     INTEGER      NOT NULL DEFAULT 1,
                booked       INTEGER      NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE time_slot_bookings (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id    INTEGER      NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                note       TEXT         NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'confirmed\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ts = new TimeSlot($this->pdo);
    }

    // ── createSlot / findSlot ─────────────────────────────────────────────────

    public function testCreateSlotReturnsId(): void
    {
        $id = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateSlotStoresCorrectly(): void
    {
        $id  = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 2);
        $row = $this->ts->findSlot($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('doc-1', $row['resource_ref']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['capacity']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['booked']);
    }

    public function testCreateSlotThrowsOnEmptyResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->createSlot('', '2026-06-01', '09:00', '09:30');
    }

    public function testCreateSlotThrowsOnZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 0);
    }

    // ── deleteSlot ────────────────────────────────────────────────────────────

    public function testDeleteSlotRemovesSlotAndBookings(): void
    {
        $id = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');
        $this->ts->book($id, 'u1');
        $this->assertTrue($this->ts->deleteSlot($id));
        $this->assertNull($this->ts->findSlot($id));
        $this->assertCount(0, $this->ts->bookingsFor($id));
    }

    public function testDeleteSlotReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->ts->deleteSlot(9999));
    }

    // ── availableSlots ────────────────────────────────────────────────────────

    public function testAvailableSlotsReturnsOpenSlots(): void
    {
        $id1 = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 1);
        $id2 = $this->ts->createSlot('doc-1', '2026-06-01', '10:00', '10:30', 1);
        $this->ts->book($id1, 'u1'); // fills slot 1

        $avail = $this->ts->availableSlots('doc-1', '2026-06-01');
        $this->assertCount(1, $avail);
        $this->assertSame($id2, (int)$avail[0]['id']);
    }

    public function testAvailableSlotsOrderedByStartTime(): void
    {
        $id2 = $this->ts->createSlot('doc-1', '2026-06-01', '14:00', '14:30');
        $id1 = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');

        $avail = $this->ts->availableSlots('doc-1', '2026-06-01');
        $this->assertSame($id1, (int)$avail[0]['id']);
        $this->assertSame($id2, (int)$avail[1]['id']);
    }

    // ── book ──────────────────────────────────────────────────────────────────

    public function testBookReturnsBookingId(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');
        $bid    = $this->ts->book($slotId, 'u1');
        $this->assertIsInt($bid);
        $this->assertGreaterThan(0, $bid);
    }

    public function testBookIncrementsBookedCount(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 2);
        $this->ts->book($slotId, 'u1');

        $row = $this->ts->findSlot($slotId);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['booked']);
    }

    public function testBookReturnsFalseWhenFull(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 1);
        $this->ts->book($slotId, 'u1');
        $this->assertFalse($this->ts->book($slotId, 'u2'));
    }

    public function testBookThrowsOnEmptyUserId(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->book($slotId, '');
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelFreesSlot(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 1);
        $bid    = $this->ts->book($slotId, 'u1');
        $this->assertIsInt($bid);
        $this->assertTrue($this->ts->cancel($bid));

        $row = $this->ts->findSlot($slotId);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['booked']);
    }

    public function testCancelReturnsFalseForAlreadyCancelled(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30');
        $bid    = $this->ts->book($slotId, 'u1');
        $this->assertIsInt($bid);
        $this->ts->cancel($bid);
        $this->assertFalse($this->ts->cancel($bid));
    }

    // ── bookingsFor ───────────────────────────────────────────────────────────

    public function testBookingsForReturnsSlotBookings(): void
    {
        $slotId = $this->ts->createSlot('doc-1', '2026-06-01', '09:00', '09:30', 3);
        $this->ts->book($slotId, 'u1');
        $this->ts->book($slotId, 'u2');

        $bookings = $this->ts->bookingsFor($slotId);
        $this->assertCount(2, $bookings);
    }
}
