<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\WebhookDelivery;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookDelivery.
 */
final class WebhookDeliveryTest extends TestCase
{
    private PDO $db;
    private WebhookDelivery $wd;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE webhook_deliveries (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint_id     VARCHAR(255) NOT NULL,
                event_type      VARCHAR(100) NOT NULL,
                payload         TEXT         NOT NULL DEFAULT \'{}\',
                status          VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                attempts        INT          NOT NULL DEFAULT 0,
                max_attempts    INT          NOT NULL DEFAULT 5,
                http_status     INT          NOT NULL DEFAULT 0,
                response_body   TEXT         NOT NULL DEFAULT \'\',
                next_attempt_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivered_at    DATETIME     DEFAULT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->wd = new WebhookDelivery($this->db, 5);
    }

    // ── schedule ──────────────────────────────────────────────────────────────

    public function testScheduleReturnsId(): void
    {
        $id = $this->wd->schedule('ep-1', 'user.created');
        $this->assertGreaterThan(0, $id);
    }

    public function testScheduleStoresPayload(): void
    {
        $id = $this->wd->schedule('ep-1', 'order.placed', ['amount' => 99]);
        $d  = $this->wd->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(['amount' => 99], $d['payload']);
    }

    public function testScheduleStatusIsPending(): void
    {
        $id = $this->wd->schedule('ep-1', 'ping');
        $d  = $this->wd->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $d['status']);
    }

    public function testScheduleThrowsOnEmptyEndpointId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wd->schedule('', 'ping');
    }

    public function testScheduleThrowsOnEmptyEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wd->schedule('ep-1', '');
    }

    public function testScheduleWithDelayIsNotImmediatelyClaimed(): void
    {
        $this->wd->schedule('ep-1', 'ping', [], 3600);
        $this->assertNull($this->wd->claimNext());
    }

    // ── claimNext ─────────────────────────────────────────────────────────────

    public function testClaimNextReturnsDelivery(): void
    {
        $this->wd->schedule('ep-1', 'ping');
        $d = $this->wd->claimNext();
        $this->assertNotNull($d);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('sending', $d['status']);
    }

    public function testClaimNextIncrementsAttempts(): void
    {
        $this->wd->schedule('ep-1', 'ping');
        $d = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$d['attempts']);
    }

    public function testClaimNextReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->wd->claimNext());
    }

    public function testClaimNextReturnsOldestFirst(): void
    {
        $id1 = $this->wd->schedule('ep-1', 'first');
        $id2 = $this->wd->schedule('ep-1', 'second');
        $d   = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id1, (int)$d['id']);
    }

    // ── succeed ───────────────────────────────────────────────────────────────

    public function testSucceedMarksDelivered(): void
    {
        $this->wd->schedule('ep-1', 'ping');
        $d = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertTrue($this->wd->succeed((int)$d['id'], 200, 'ok'));
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $this->wd->find((int)$d['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('delivered', $updated['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(200, (int)$updated['http_status']);
    }

    public function testSucceedReturnsFalseIfNotSending(): void
    {
        $id = $this->wd->schedule('ep-1', 'ping');
        $this->assertFalse($this->wd->succeed($id, 200));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailResetsToPendingWhenAttemptsRemain(): void
    {
        $wd = new WebhookDelivery($this->db, 3);
        $wd->schedule('ep-1', 'ping');
        $d = $wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $wd->fail((int)$d['id'], 500, 'error');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $wd->find((int)$d['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $updated['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('error', $updated['response_body']);
    }

    public function testFailMarksFailedWhenMaxAttemptsReached(): void
    {
        $wd = new WebhookDelivery($this->db, 1);
        $wd->schedule('ep-1', 'ping');
        $d = $wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $wd->fail((int)$d['id'], 0, 'timeout');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $wd->find((int)$d['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $updated['status']);
    }

    public function testFailReturnsFalseIfNotSending(): void
    {
        $id = $this->wd->schedule('ep-1', 'ping');
        $this->assertFalse($this->wd->fail($id, 0));
    }

    // ── listPending ───────────────────────────────────────────────────────────

    public function testListPendingReturnsReadyDeliveries(): void
    {
        $this->wd->schedule('ep-1', 'a');
        $this->wd->schedule('ep-1', 'b');
        $list = $this->wd->listPending('ep-1');
        $this->assertCount(2, $list);
    }

    public function testListPendingIsEndpointScoped(): void
    {
        $this->wd->schedule('ep-1', 'ping');
        $this->wd->schedule('ep-2', 'ping');
        $list = $this->wd->listPending('ep-1');
        $this->assertCount(1, $list);
    }

    public function testListPendingExcludesSending(): void
    {
        $this->wd->schedule('ep-1', 'ping');
        $this->wd->claimNext();
        $this->assertCount(0, $this->wd->listPending('ep-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsTotal(): void
    {
        $this->wd->schedule('ep-1', 'a');
        $this->wd->schedule('ep-1', 'b');
        $this->assertSame(2, $this->wd->count());
    }

    public function testCountByStatus(): void
    {
        $this->wd->schedule('ep-1', 'a');
        $d = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->wd->succeed((int)$d['id'], 200);
        $this->wd->schedule('ep-1', 'b');
        $this->assertSame(1, $this->wd->count('pending'));
        $this->assertSame(1, $this->wd->count('delivered'));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeDeletesOldDelivered(): void
    {
        $id = $this->wd->schedule('ep-1', 'ping');
        $d  = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->wd->succeed((int)$d['id'], 200);
        $this->db->exec("UPDATE webhook_deliveries SET created_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->wd->purge(1);
        $this->assertSame(1, $deleted);
    }

    public function testPurgeDoesNotDeleteRecent(): void
    {
        $id = $this->wd->schedule('ep-1', 'ping');
        $d  = $this->wd->claimNext();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->wd->succeed((int)$d['id'], 200);
        $this->assertSame(0, $this->wd->purge(30));
    }
}
