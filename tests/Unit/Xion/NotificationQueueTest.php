<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\NotificationQueue;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationQueueTest extends TestCase
{
    private PDO $pdo;
    private NotificationQueue $nq;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE notification_queue (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient_id  VARCHAR(255) NOT NULL,
                channel       VARCHAR(50)  NOT NULL DEFAULT \'push\',
                subject       VARCHAR(255) NOT NULL,
                payload       TEXT         NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                attempts      INTEGER      NOT NULL DEFAULT 0,
                max_attempts  INTEGER      NOT NULL DEFAULT 3,
                error_message TEXT         NULL,
                scheduled_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at       DATETIME     NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->nq = new NotificationQueue($this->pdo);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->nq->enqueue('user-1', NotificationQueue::CHANNEL_PUSH, 'Hello');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueStoresCorrectly(): void
    {
        $id  = $this->nq->enqueue('user-1', NotificationQueue::CHANNEL_PUSH, 'Hello', ['badge' => 1]);
        $row = $this->nq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['recipient_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(NotificationQueue::CHANNEL_PUSH, $row['channel']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hello', $row['subject']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"badge":1}', $row['payload']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(NotificationQueue::STATUS_PENDING, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['attempts']);
    }

    public function testEnqueueStringPayload(): void
    {
        $id  = $this->nq->enqueue('user-1', 'push', 'Hi', 'raw-payload');
        $row = $this->nq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('raw-payload', $row['payload']);
    }

    public function testEnqueueThrowsOnEmptyRecipientId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nq->enqueue('', 'push', 'Hello');
    }

    public function testEnqueueThrowsOnEmptySubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nq->enqueue('user-1', 'push', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->nq->find(9999));
    }

    // ── dequeue ───────────────────────────────────────────────────────────────

    public function testDequeueReturnsPendingItems(): void
    {
        $id1 = $this->nq->enqueue('user-1', 'push', 'A');
        $id2 = $this->nq->enqueue('user-2', 'push', 'B');
        $this->nq->markSent($id1);

        $batch = $this->nq->dequeue();
        $this->assertCount(1, $batch);
        $this->assertSame($id2, (int)$batch[0]['id']);
    }

    public function testDequeueExcludesExhaustedItems(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'A', null, 1);
        $this->nq->markFailed($id, 'err');
        // After 1 attempt with max_attempts=1, should be FAILED
        $this->assertSame([], $this->nq->dequeue());
    }

    public function testDequeueRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->nq->enqueue('user-1', 'push', 'msg');
        }
        $this->assertCount(3, $this->nq->dequeue(3));
    }

    // ── markSent ──────────────────────────────────────────────────────────────

    public function testMarkSentUpdateStatus(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Hello');
        $this->assertTrue($this->nq->markSent($id));
        $row = $this->nq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(NotificationQueue::STATUS_SENT, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['sent_at']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testMarkSentReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->nq->markSent(9999));
    }

    // ── markFailed ────────────────────────────────────────────────────────────

    public function testMarkFailedIncrementsAttempts(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Hello', null, 3);
        $this->assertTrue($this->nq->markFailed($id, 'Timeout'));
        $row = $this->nq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['attempts']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(NotificationQueue::STATUS_PENDING, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Timeout', $row['error_message']);
    }

    public function testMarkFailedSetsFailedWhenAttemptsExhausted(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Hello', null, 1);
        $this->nq->markFailed($id, 'err1');
        $row = $this->nq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(NotificationQueue::STATUS_FAILED, $row['status']);
    }

    public function testMarkFailedReturnsFalseForNonPendingItem(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Hello');
        $this->nq->markSent($id);
        $this->assertFalse($this->nq->markFailed($id));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesItem(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Hello');
        $this->assertTrue($this->nq->delete($id));
        $this->assertNull($this->nq->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->nq->delete(9999));
    }

    // ── pendingFor ────────────────────────────────────────────────────────────

    public function testPendingForReturnsOnlyPendingForUser(): void
    {
        $id1 = $this->nq->enqueue('user-1', 'push', 'A');
        $this->nq->enqueue('user-1', 'push', 'B');
        $this->nq->markSent($id1);

        $pending = $this->nq->pendingFor('user-1');
        $this->assertCount(1, $pending);
    }

    public function testPendingForIsIsolatedByRecipient(): void
    {
        $this->nq->enqueue('user-1', 'push', 'A');
        $this->nq->enqueue('user-2', 'push', 'B');
        $this->assertCount(1, $this->nq->pendingFor('user-1'));
    }

    // ── purgeSent ────────────────────────────────────────────────────────────

    public function testPurgeSentDeletesOldSentItems(): void
    {
        $id = $this->nq->enqueue('user-1', 'push', 'Old');
        $this->nq->markSent($id);
        // Manually backdate sent_at
        $past = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
        $this->pdo->exec("UPDATE notification_queue SET sent_at = '{$past}' WHERE id = {$id}");

        $this->nq->enqueue('user-1', 'push', 'Recent');

        $cutoff = new \DateTimeImmutable('-1 day');
        $count  = $this->nq->purgeSent($cutoff);
        $this->assertSame(1, $count);
    }

    public function testPurgeSentReturnsZeroWhenNone(): void
    {
        $cutoff = new \DateTimeImmutable('-1 day');
        $this->assertSame(0, $this->nq->purgeSent($cutoff));
    }
}
