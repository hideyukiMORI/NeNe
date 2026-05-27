<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\EventRsvp;
use PDO;
use PHPUnit\Framework\TestCase;

final class EventRsvpTest extends TestCase
{
    private PDO $pdo;
    private EventRsvp $rsvp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE event_rsvp_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                event_ref  VARCHAR(255) NOT NULL,
                title      TEXT         NOT NULL,
                starts_at  DATETIME     NOT NULL,
                capacity   INTEGER      NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'open\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE event_rsvp_responses (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id     INTEGER      NOT NULL,
                user_id      VARCHAR(255) NOT NULL,
                response     VARCHAR(20)  NOT NULL,
                guests       INTEGER      NOT NULL DEFAULT 0,
                checked_in   INTEGER      NOT NULL DEFAULT 0,
                responded_at DATETIME     NOT NULL,
                UNIQUE (event_id, user_id)
            )
        ');
        $this->rsvp = new EventRsvp($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresFields(): void
    {
        $id  = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00', 200);
        $row = $this->rsvp->getEvent($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('evt-1', $row['event_ref']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hackathon', $row['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(200, (int)$row['capacity']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(EventRsvp::STATUS_OPEN, $row['status']);
    }

    public function testCreateWithoutCapacity(): void
    {
        $id  = $this->rsvp->create('evt-2', 'Open Day', '2026-07-01 10:00:00');
        $row = $this->rsvp->getEvent($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['capacity']);
    }

    public function testCreateThrowsOnEmptyEventRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rsvp->create('', 'Title', '2026-06-15 09:00:00');
    }

    public function testCreateThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rsvp->create('evt-1', '', '2026-06-15 09:00:00');
    }

    public function testCreateThrowsOnEmptyStartsAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rsvp->create('evt-1', 'Title', '');
    }

    // ── getEvent ──────────────────────────────────────────────────────────────

    public function testGetEventReturnsNullForMissingId(): void
    {
        $this->assertNull($this->rsvp->getEvent(9999));
    }

    // ── respond ───────────────────────────────────────────────────────────────

    public function testRespondRecordsYes(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertTrue($this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES));
        $row = $this->rsvp->getUserResponse($id, 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(EventRsvp::RESPONSE_YES, $row['response']);
    }

    public function testRespondRecordsNo(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertTrue($this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_NO));
        $row = $this->rsvp->getUserResponse($id, 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(EventRsvp::RESPONSE_NO, $row['response']);
    }

    public function testRespondCanChangeResponse(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_MAYBE);
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES);
        $row = $this->rsvp->getUserResponse($id, 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(EventRsvp::RESPONSE_YES, $row['response']);
    }

    public function testRespondWithGuests(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES, 2);
        $row = $this->rsvp->getUserResponse($id, 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['guests']);
    }

    public function testRespondReturnsFalseWhenClosed(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->close($id);
        $this->assertFalse($this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES));
    }

    public function testRespondReturnsFalseForMissingEvent(): void
    {
        $this->assertFalse($this->rsvp->respond(9999, 'user-1', EventRsvp::RESPONSE_YES));
    }

    public function testRespondThrowsOnInvalidResponse(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->rsvp->respond($id, 'user-1', 'going');
    }

    public function testRespondThrowsOnEmptyUserId(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->rsvp->respond($id, '', EventRsvp::RESPONSE_YES);
    }

    public function testRespondReturnsFalseWhenCapacityExceeded(): void
    {
        $id = $this->rsvp->create('evt-1', 'Small Event', '2026-06-15 09:00:00', 2);
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES);
        $this->rsvp->respond($id, 'user-2', EventRsvp::RESPONSE_YES);
        $this->assertFalse($this->rsvp->respond($id, 'user-3', EventRsvp::RESPONSE_YES));
    }

    // ── getUserResponse ───────────────────────────────────────────────────────

    public function testGetUserResponseReturnsNullWhenNotResponded(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertNull($this->rsvp->getUserResponse($id, 'user-1'));
    }

    // ── checkin ───────────────────────────────────────────────────────────────

    public function testCheckinSetsFlag(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES);
        $this->assertTrue($this->rsvp->checkin($id, 'user-1'));
        $row = $this->rsvp->getUserResponse($id, 'user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['checked_in']);
    }

    public function testCheckinReturnsFalseIfNotYes(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_MAYBE);
        $this->assertFalse($this->rsvp->checkin($id, 'user-1'));
    }

    public function testCheckinReturnsFalseIfNotResponded(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertFalse($this->rsvp->checkin($id, 'user-1'));
    }

    // ── acceptedCount ─────────────────────────────────────────────────────────

    public function testAcceptedCountIncludesGuests(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES, 1); // 2 total
        $this->rsvp->respond($id, 'user-2', EventRsvp::RESPONSE_YES, 0); // 1 total
        $this->assertSame(3, $this->rsvp->acceptedCount($id));
    }

    public function testAcceptedCountIgnoresMaybeAndNo(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_MAYBE);
        $this->rsvp->respond($id, 'user-2', EventRsvp::RESPONSE_NO);
        $this->assertSame(0, $this->rsvp->acceptedCount($id));
    }

    // ── responses ─────────────────────────────────────────────────────────────

    public function testResponsesFiltersCorrectly(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES);
        $this->rsvp->respond($id, 'user-2', EventRsvp::RESPONSE_NO);
        $this->rsvp->respond($id, 'user-3', EventRsvp::RESPONSE_MAYBE);
        $this->assertCount(1, $this->rsvp->responses($id, EventRsvp::RESPONSE_YES));
        $this->assertCount(3, $this->rsvp->responses($id));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseChangesStatus(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->assertTrue($this->rsvp->close($id));
        $row = $this->rsvp->getEvent($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(EventRsvp::STATUS_CLOSED, $row['status']);
    }

    public function testCloseReturnsFalseWhenAlreadyClosed(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->close($id);
        $this->assertFalse($this->rsvp->close($id));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesEventAndResponses(): void
    {
        $id = $this->rsvp->create('evt-1', 'Hackathon', '2026-06-15 09:00:00');
        $this->rsvp->respond($id, 'user-1', EventRsvp::RESPONSE_YES);
        $this->assertTrue($this->rsvp->delete($id));
        $this->assertNull($this->rsvp->getEvent($id));
        $this->assertSame([], $this->rsvp->responses($id));
    }

    public function testDeleteReturnsFalseForMissingEvent(): void
    {
        $this->assertFalse($this->rsvp->delete(9999));
    }
}
