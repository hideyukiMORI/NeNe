<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DeadLetterQueue;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeadLetterQueue.
 */
final class DeadLetterQueueTest extends TestCase
{
    private PDO $db;
    private DeadLetterQueue $dlq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE dead_letters (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                queue     VARCHAR(100) NOT NULL,
                payload   TEXT         NOT NULL,
                error     TEXT         NOT NULL DEFAULT \'\',
                attempts  INTEGER      NOT NULL DEFAULT 0,
                failed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dlq = new DeadLetterQueue($this->db);
    }

    public function testRecordAndGet(): void
    {
        $id    = $this->dlq->record('emails', '{"to":"x@y.com"}', 'SMTP 550', 5);
        $entry = $this->dlq->get($id);
        $this->assertNotNull($entry);
        $this->assertSame('emails', $entry['queue']);
        $this->assertSame('{"to":"x@y.com"}', $entry['payload']);
        $this->assertSame('SMTP 550', $entry['error']);
        $this->assertSame(5, $entry['attempts']);
    }

    public function testGetMissingIsNull(): void
    {
        $this->assertNull($this->dlq->get(999));
    }

    public function testForQueueNewestFirst(): void
    {
        $this->dlq->record('emails', 'a');
        $this->dlq->record('emails', 'b');
        $this->dlq->record('sms', 'c');
        $list = $this->dlq->forQueue('emails');
        $this->assertCount(2, $list);
        $this->assertSame('b', $list[0]['payload']); // newest (higher id) first
    }

    public function testForQueueLimit(): void
    {
        $this->dlq->record('emails', 'a');
        $this->dlq->record('emails', 'b');
        $this->dlq->record('emails', 'c');
        $this->assertCount(2, $this->dlq->forQueue('emails', 2));
    }

    public function testCountTotalAndPerQueue(): void
    {
        $this->dlq->record('emails', 'a');
        $this->dlq->record('emails', 'b');
        $this->dlq->record('sms', 'c');
        $this->assertSame(3, $this->dlq->count());
        $this->assertSame(2, $this->dlq->count('emails'));
        $this->assertSame(0, $this->dlq->count('push'));
    }

    public function testQueuesSummaryBusiestFirst(): void
    {
        $this->dlq->record('emails', 'a');
        $this->dlq->record('emails', 'b');
        $this->dlq->record('sms', 'c');
        $q = $this->dlq->queues();
        $this->assertSame('emails', $q[0]['queue']);
        $this->assertSame(2, $q[0]['count']);
        $this->assertSame('sms', $q[1]['queue']);
    }

    public function testRemove(): void
    {
        $id = $this->dlq->record('emails', 'a');
        $this->dlq->remove($id);
        $this->assertNull($this->dlq->get($id));
        $this->assertSame(0, $this->dlq->count());
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->dlq->remove(999);
        $this->assertSame(0, $this->dlq->count());
    }

    public function testRequeueReturnsAndRemoves(): void
    {
        $id    = $this->dlq->record('emails', 'payload-x', 'boom', 3);
        $entry = $this->dlq->requeue($id);
        $this->assertNotNull($entry);
        $this->assertSame('payload-x', $entry['payload']);
        $this->assertSame(3, $entry['attempts']);
        // It is gone after claiming.
        $this->assertNull($this->dlq->get($id));
        $this->assertSame(0, $this->dlq->count());
    }

    public function testRequeueMissingIsNull(): void
    {
        $this->assertNull($this->dlq->requeue(999));
    }

    public function testPurgeOlderThanRemovesAgedRows(): void
    {
        $this->db->exec("INSERT INTO dead_letters (queue, payload, failed_at) VALUES ('q', 'old', '2026-01-01 00:00:00')");
        $this->db->exec("INSERT INTO dead_letters (queue, payload, failed_at) VALUES ('q', 'new', '2026-05-29 00:00:00')");
        // asOf 2026-05-29, purge older than 90 days → cutoff ~2026-02-28; 'old' removed
        $removed = $this->dlq->purgeOlderThan(90, '2026-05-29 00:00:00');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->dlq->count());
    }

    public function testRecordRejectsEmptyQueue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dlq->record('  ', 'x');
    }

    public function testRecordRejectsNegativeAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dlq->record('emails', 'x', '', -1);
    }

    public function testPurgeRejectsNegativeDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dlq->purgeOlderThan(-1);
    }
}
