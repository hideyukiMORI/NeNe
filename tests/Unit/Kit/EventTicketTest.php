<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EventTicket;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventTicket.
 */
final class EventTicketTest extends TestCase
{
    private PDO $db;
    private EventTicket $et;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE event_tickets (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id      VARCHAR(255) NOT NULL,
                user_id       VARCHAR(255) NOT NULL,
                code          VARCHAR(16)  NOT NULL UNIQUE,
                status        VARCHAR(20)  NOT NULL DEFAULT \'issued\',
                checked_in_at DATETIME     DEFAULT NULL,
                cancelled_at  DATETIME     DEFAULT NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE event_capacities (
                event_id   VARCHAR(255) NOT NULL PRIMARY KEY,
                capacity   INTEGER      DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->et = new EventTicket($this->db);
    }

    // ── setCapacity / capacity ────────────────────────────────────────────────

    public function testSetCapacitySetsLimit(): void
    {
        $this->et->setCapacity('event-1', 100);
        $this->assertSame(100, $this->et->capacity('event-1'));
    }

    public function testSetCapacityNullIsUnlimited(): void
    {
        $this->et->setCapacity('event-1', null);
        $this->assertNull($this->et->capacity('event-1'));
    }

    public function testSetCapacityThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->et->setCapacity('event-1', -1);
    }

    public function testCapacityReturnsNullIfNotSet(): void
    {
        $this->assertNull($this->et->capacity('event-1'));
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturns16HexCode(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $code);
    }

    public function testIssueThrowsIfUserAlreadyHasTicket(): void
    {
        $this->et->issue('event-1', 'user-1');
        $this->expectException(\RuntimeException::class);
        $this->et->issue('event-1', 'user-1');
    }

    public function testIssueThrowsIfAtCapacity(): void
    {
        $this->et->setCapacity('event-1', 1);
        $this->et->issue('event-1', 'user-1');
        $this->expectException(\RuntimeException::class);
        $this->et->issue('event-1', 'user-2');
    }

    public function testIssueAfterCancelFreesSlot(): void
    {
        $this->et->setCapacity('event-1', 1);
        $code = $this->et->issue('event-1', 'user-1');
        $this->et->cancel($code);
        // Now there is a free slot
        $this->et->issue('event-1', 'user-2'); // should not throw
        $this->assertSame(1, $this->et->issuedCount('event-1'));
    }

    // ── checkIn ───────────────────────────────────────────────────────────────

    public function testCheckInUpdatesStatus(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->assertTrue($this->et->checkIn($code));
        $row = $this->et->findByCode($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('checked_in', $row['status']);
    }

    public function testCheckInReturnsFalseForAlreadyCheckedIn(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->et->checkIn($code);
        $this->assertFalse($this->et->checkIn($code));
    }

    public function testCheckInReturnsFalseForUnknownCode(): void
    {
        $this->assertFalse($this->et->checkIn('deadbeef00000000'));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelSetsStatusCancelled(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->assertTrue($this->et->cancel($code));
        $row = $this->et->findByCode($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('cancelled', $row['status']);
    }

    public function testCancelReturnsFalseForCheckedInTicket(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->et->checkIn($code);
        $this->assertFalse($this->et->cancel($code));
    }

    // ── findByUser ────────────────────────────────────────────────────────────

    public function testFindByUserReturnsActiveTicket(): void
    {
        $this->et->issue('event-1', 'user-1');
        $row = $this->et->findByUser('event-1', 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('issued', $row['status']);
    }

    public function testFindByUserReturnsNullIfNoneExists(): void
    {
        $this->assertNull($this->et->findByUser('event-1', 'user-nobody'));
    }

    // ── issuedCount / checkedInCount / isFull / remainingSlots ────────────────

    public function testIssuedCountReflectsActiveTickets(): void
    {
        $this->et->issue('event-1', 'user-1');
        $this->et->issue('event-1', 'user-2');
        $this->assertSame(2, $this->et->issuedCount('event-1'));
    }

    public function testCheckedInCountReflectsCheckedInUsers(): void
    {
        $code = $this->et->issue('event-1', 'user-1');
        $this->et->checkIn($code);
        $this->assertSame(1, $this->et->checkedInCount('event-1'));
    }

    public function testIsFullReturnsTrueAtCapacity(): void
    {
        $this->et->setCapacity('event-1', 2);
        $this->et->issue('event-1', 'user-1');
        $this->et->issue('event-1', 'user-2');
        $this->assertTrue($this->et->isFull('event-1'));
    }

    public function testRemainingSlotsShrinkAsTicketsIssued(): void
    {
        $this->et->setCapacity('event-1', 5);
        $this->et->issue('event-1', 'user-1');
        $this->assertSame(4, $this->et->remainingSlots('event-1'));
    }

    public function testRemainingSlotIsNullForUnlimitedEvent(): void
    {
        $this->assertNull($this->et->remainingSlots('event-1'));
    }
}
