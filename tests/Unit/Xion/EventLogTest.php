<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\EventLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventLog.
 */
final class EventLogTest extends TestCase
{
    private PDO $db;
    private EventLog $el;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE event_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type     VARCHAR(100) NOT NULL,
                aggregate_type VARCHAR(100) NOT NULL DEFAULT \'\',
                aggregate_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                data           TEXT         NOT NULL DEFAULT \'{}\',
                occurred_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->el = new EventLog($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->el->record('user_registered');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresEventType(): void
    {
        $id   = $this->el->record('order_placed', 'order', 'o-1', ['total' => 99]);
        $rows = $this->el->forAggregate('order', 'o-1');
        $this->assertSame('order_placed', $rows[0]['event_type']);
    }

    public function testRecordStoresData(): void
    {
        $id   = $this->el->record('payment_failed', 'payment', 'p-1', ['reason' => 'declined']);
        $rows = $this->el->forAggregate('payment', 'p-1');
        $this->assertSame(['reason' => 'declined'], $rows[0]['data']);
    }

    public function testRecordThrowsOnEmptyEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->el->record('');
    }

    // ── forAggregate ──────────────────────────────────────────────────────────

    public function testForAggregateReturnsEventsInOrder(): void
    {
        $this->el->record('created', 'user', 'u-1');
        $this->el->record('updated', 'user', 'u-1');
        $this->el->record('deleted', 'user', 'u-1');

        $rows = $this->el->forAggregate('user', 'u-1');
        $this->assertCount(3, $rows);
        $this->assertSame('created', $rows[0]['event_type']);
        $this->assertSame('updated', $rows[1]['event_type']);
        $this->assertSame('deleted', $rows[2]['event_type']);
    }

    public function testForAggregateReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->el->forAggregate('user', 'nobody'));
    }

    public function testForAggregateIsScopedToAggregate(): void
    {
        $this->el->record('event', 'user', 'u-1');
        $this->el->record('event', 'user', 'u-2');
        $rows = $this->el->forAggregate('user', 'u-1');
        $this->assertCount(1, $rows);
    }

    // ── forEvent ──────────────────────────────────────────────────────────────

    public function testForEventReturnsMatchingEvents(): void
    {
        $this->el->record('login', 'user', 'u-1');
        $this->el->record('login', 'user', 'u-2');
        $this->el->record('logout', 'user', 'u-1');

        $rows = $this->el->forEvent('login');
        $this->assertCount(2, $rows);
    }

    public function testForEventReturnsInDescendingOrder(): void
    {
        $this->el->record('ping', 'svc', 's-1');
        $this->el->record('ping', 'svc', 's-2');
        $rows = $this->el->forEvent('ping', 10);
        $this->assertGreaterThan((int)$rows[1]['id'], (int)$rows[0]['id']);
    }

    public function testForEventThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->el->forEvent('');
    }

    // ── since ─────────────────────────────────────────────────────────────────

    public function testSinceReturnsEventsAfterCursor(): void
    {
        $id1 = $this->el->record('a');
        $id2 = $this->el->record('b');
        $id3 = $this->el->record('c');

        $rows = $this->el->since($id1, 100);
        $this->assertCount(2, $rows);
        $this->assertSame($id2, (int)$rows[0]['id']);
        $this->assertSame($id3, (int)$rows[1]['id']);
    }

    public function testSinceReturnsEmptyFromLatestId(): void
    {
        $id = $this->el->record('last');
        $this->assertSame([], $this->el->since($id, 100));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsLatestFirst(): void
    {
        $this->el->record('first');
        $this->el->record('second');
        $this->el->record('third');
        $rows = $this->el->recent(2);
        $this->assertCount(2, $rows);
        $this->assertSame('third', $rows[0]['event_type']);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsTotal(): void
    {
        $this->el->record('a');
        $this->el->record('b');
        $this->el->record('a');
        $this->assertSame(3, $this->el->count());
    }

    public function testCountByEventType(): void
    {
        $this->el->record('login');
        $this->el->record('login');
        $this->el->record('logout');
        $this->assertSame(2, $this->el->count('login'));
        $this->assertSame(1, $this->el->count('logout'));
    }

    public function testCountReturnsZeroForUnknownType(): void
    {
        $this->assertSame(0, $this->el->count('no_such_event'));
    }

    // ── countByType ───────────────────────────────────────────────────────────

    public function testCountByTypeReturnsMap(): void
    {
        $this->el->record('login');
        $this->el->record('login');
        $this->el->record('logout');
        $map = $this->el->countByType();
        $this->assertSame(2, $map['login']);
        $this->assertSame(1, $map['logout']);
    }

    public function testCountByTypeReturnsEmptyForNoEvents(): void
    {
        $this->assertSame([], $this->el->countByType());
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEvents(): void
    {
        $id = $this->el->record('old_event');
        $this->db->exec("UPDATE event_log SET occurred_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->el->purgeOlderThan(1);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->el->count());
    }

    public function testPurgeOlderThanLeavesRecentEvents(): void
    {
        $this->el->record('recent');
        $this->assertSame(0, $this->el->purgeOlderThan(30));
    }
}
