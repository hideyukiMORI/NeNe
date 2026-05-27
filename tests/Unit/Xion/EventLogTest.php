<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\EventLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class EventLogTest extends TestCase
{
    private PDO $pdo;
    private EventLog $el;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE event_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type     VARCHAR(100) NOT NULL,
                aggregate_type VARCHAR(100) NOT NULL DEFAULT \'\',
                aggregate_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                actor_id       VARCHAR(255) NOT NULL DEFAULT \'\',
                payload        TEXT         NOT NULL DEFAULT \'{}\',
                occurred_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->el = new EventLog($this->pdo);
    }

    // ── append ────────────────────────────────────────────────────────────────

    public function testAppendReturnsId(): void
    {
        $id = $this->el->append('OrderPlaced', 'order', '99');
        $this->assertGreaterThan(0, $id);
    }

    public function testAppendStoresAllFields(): void
    {
        $id  = $this->el->append('OrderPlaced', 'order', '99', 'user-1', ['total' => 9900]);
        $row = $this->el->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('OrderPlaced', $row['event_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order', $row['aggregate_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('99', $row['aggregate_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['actor_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('9900', $row['payload']);
    }

    public function testAppendThrowsOnEmptyEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->el->append('');
    }

    public function testAppendWithMinimalArgs(): void
    {
        $id  = $this->el->append('SystemStarted');
        $row = $this->el->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('[]', $row['payload']);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->el->find(9999));
    }

    // ── forAggregate ──────────────────────────────────────────────────────────

    public function testForAggregateReturnsEventsInOrder(): void
    {
        $id1 = $this->el->append('Created', 'order', '1');
        $id2 = $this->el->append('Updated', 'order', '1');
        $this->el->append('Created', 'order', '2');

        $events = $this->el->forAggregate('order', '1');
        $this->assertCount(2, $events);
        $this->assertSame($id1, (int)$events[0]['id']);
        $this->assertSame($id2, (int)$events[1]['id']);
    }

    public function testForAggregateReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->el->forAggregate('order', '99'));
    }

    // ── ofType ────────────────────────────────────────────────────────────────

    public function testOfTypeReturnsMatchingEvents(): void
    {
        $this->el->append('OrderPlaced', 'order', '1');
        $this->el->append('OrderPlaced', 'order', '2');
        $this->el->append('OrderShipped', 'order', '1');

        $events = $this->el->ofType('OrderPlaced');
        $this->assertCount(2, $events);
    }

    public function testOfTypeReturnsNewestFirst(): void
    {
        $id1 = $this->el->append('OrderPlaced', 'order', '1');
        $id2 = $this->el->append('OrderPlaced', 'order', '2');

        $events = $this->el->ofType('OrderPlaced');
        $this->assertSame($id2, (int)$events[0]['id']);
        $this->assertSame($id1, (int)$events[1]['id']);
    }

    // ── byActor ───────────────────────────────────────────────────────────────

    public function testByActorReturnsActorEvents(): void
    {
        $this->el->append('OrderPlaced', 'order', '1', 'user-1');
        $this->el->append('OrderPlaced', 'order', '2', 'user-1');
        $this->el->append('OrderPlaced', 'order', '3', 'user-2');

        $events = $this->el->byActor('user-1');
        $this->assertCount(2, $events);
    }

    public function testByActorThrowsOnEmptyActorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->el->byActor('');
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsNewestFirst(): void
    {
        $id1 = $this->el->append('A', 'x', '1');
        $id2 = $this->el->append('B', 'x', '1');

        $events = $this->el->recent(10);
        $this->assertSame($id2, (int)$events[0]['id']);
        $this->assertSame($id1, (int)$events[1]['id']);
    }

    // ── countByType ───────────────────────────────────────────────────────────

    public function testCountByTypeGroupsCorrectly(): void
    {
        $this->el->append('A');
        $this->el->append('A');
        $this->el->append('B');

        $counts = $this->el->countByType();
        $this->assertSame(2, $counts['A']);
        $this->assertSame(1, $counts['B']);
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        // Insert an event with an old occurred_at
        $this->pdo->exec(
            "INSERT INTO event_log (event_type, occurred_at) VALUES ('OldEvent', '2000-01-01 00:00:00')"
        );
        $this->el->append('NewEvent');

        $n = $this->el->purgeOlderThan(1);
        $this->assertSame(1, $n);

        $remaining = $this->el->ofType('OldEvent');
        $this->assertSame([], $remaining);
        $this->assertCount(1, $this->el->ofType('NewEvent'));
    }
}
