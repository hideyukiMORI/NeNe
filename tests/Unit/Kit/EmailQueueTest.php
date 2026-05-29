<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EmailQueue;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailQueueTest extends TestCase
{
    private PDO $db;
    private EmailQueue $eq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE email_queue (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                to_address   VARCHAR(255) NOT NULL,
                subject      VARCHAR(500) NOT NULL DEFAULT \'\',
                body         TEXT         NOT NULL DEFAULT \'\',
                content_type VARCHAR(50)  NOT NULL DEFAULT \'text/plain\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                attempts     INTEGER      NOT NULL DEFAULT 0,
                max_attempts INTEGER      NOT NULL DEFAULT 3,
                error        TEXT         NOT NULL DEFAULT \'\',
                send_after   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at      DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->eq = new EmailQueue($this->db, 3);
    }

    public function testEnqueueReturnsId(): void
    {
        $id = $this->eq->enqueue('user@example.com', 'Hi', 'Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueThrowsOnEmptyAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eq->enqueue('', 'Hi', 'Hello');
    }

    public function testClaimReturnsPendingEmail(): void
    {
        $id  = $this->eq->enqueue('user@example.com', 'Hi', 'Hello');
        $row = $this->eq->claim();
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testClaimReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->eq->claim());
    }

    public function testClaimIncrementsAttempts(): void
    {
        $this->eq->enqueue('a@b.com', 'x', 'y');
        $this->eq->claim();
        // After claim, mark failed to re-queue
        $this->eq->markFailed(1, 'err');
        // Manually set send_after to past for retry
        $this->db->exec("UPDATE email_queue SET send_after = '2000-01-01' WHERE id = 1");
        $row = $this->eq->claim();
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['attempts']);
    }

    public function testMarkSent(): void
    {
        $id = $this->eq->enqueue('a@b.com', 'x', 'y');
        $this->eq->claim();
        $this->assertTrue($this->eq->markSent($id));
        $row = $this->eq->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('sent', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['sent_at']);
    }

    public function testMarkFailedReschedulesBeforeMax(): void
    {
        $id = $this->eq->enqueue('a@b.com', 'x', 'y');
        $this->eq->claim();
        $this->assertTrue($this->eq->markFailed($id, 'timeout'));
        $row = $this->eq->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('timeout', $row['error']);
    }

    public function testMarkFailedPermanentlyFailsAtMax(): void
    {
        $id = $this->eq->enqueue('a@b.com', 'x', 'y');
        // Simulate max attempts reached
        $this->db->exec("UPDATE email_queue SET attempts = 3 WHERE id = {$id}");
        $this->assertTrue($this->eq->markFailed($id, 'final error'));
        $row = $this->eq->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $row['status']);
    }

    public function testMarkFailedReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->eq->markFailed(999, 'err'));
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->eq->find(999));
    }

    public function testCountAll(): void
    {
        $this->eq->enqueue('a@b.com', 'x', 'y');
        $this->eq->enqueue('b@c.com', 'x', 'y');
        $this->assertSame(2, $this->eq->count());
    }

    public function testCountByStatus(): void
    {
        $id1 = $this->eq->enqueue('a@b.com', 'x', 'y');
        $id2 = $this->eq->enqueue('b@c.com', 'x', 'y');
        $this->eq->claim();
        $this->eq->markSent($id1);
        $this->assertSame(1, $this->eq->count('sent'));
        $this->assertSame(1, $this->eq->count('pending'));
    }

    public function testPurgeSent(): void
    {
        $id = $this->eq->enqueue('a@b.com', 'x', 'y');
        $this->eq->claim();
        $this->eq->markSent($id);
        // Manually set sent_at to past
        $this->db->exec("UPDATE email_queue SET sent_at = '2000-01-01' WHERE id = {$id}");
        $deleted = $this->eq->purgeSent(1);
        $this->assertSame(1, $deleted);
    }
}
