<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ContentSchedule;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentSchedule.
 */
final class ContentScheduleTest extends TestCase
{
    private PDO $db;
    private ContentSchedule $cs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE content_schedules (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                publish_at   DATETIME     NOT NULL,
                expire_at    DATETIME     DEFAULT NULL,
                cancelled_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id)
            )
        ');
        $this->cs = new ContentSchedule($this->db);
    }

    // ── schedule ──────────────────────────────────────────────────────────────

    public function testScheduleReturnsId(): void
    {
        $id = $this->cs->schedule('post', '1', new \DateTimeImmutable());
        $this->assertGreaterThan(0, $id);
    }

    public function testScheduleIsIdempotentUpsert(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->assertSame('published', $this->cs->status('post', '1'));

        // Re-scheduling replaces the entry; new publish_at is in the future
        $this->cs->schedule('post', '1', new \DateTimeImmutable('+1 hour'));
        $this->assertSame('draft', $this->cs->status('post', '1'));

        // Only one row should exist
        $stmt = $this->db->query("SELECT COUNT(*) FROM content_schedules WHERE entity_type='post' AND entity_id='1'");
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testScheduleThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cs->schedule('', '1', new \DateTimeImmutable());
    }

    public function testScheduleThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cs->schedule('post', '', new \DateTimeImmutable());
    }

    public function testScheduleThrowsIfExpireAtNotAfterPublishAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $pub = new \DateTimeImmutable('+1 hour');
        $exp = $pub; // exactly equal — not strictly after
        $this->cs->schedule('post', '1', $pub, $exp);
    }

    // ── isLive ────────────────────────────────────────────────────────────────

    public function testIsLiveTrueForPastPublishAt(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->assertTrue($this->cs->isLive('post', '1'));
    }

    public function testIsLiveFalseForFuturePublishAt(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('+1 hour'));
        $this->assertFalse($this->cs->isLive('post', '1'));
    }

    public function testIsLiveFalseAfterExpiry(): void
    {
        // Use a past publish_at and a past expire_at via direct INSERT
        $this->db->exec(
            "INSERT INTO content_schedules (entity_type, entity_id, publish_at, expire_at)
             VALUES ('post', '99', '2000-01-01 00:00:00', '2000-01-02 00:00:00')"
        );
        $this->assertFalse($this->cs->isLive('post', '99'));
    }

    public function testIsLiveFalseForCancelledEntry(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->cs->cancel('post', '1');
        $this->assertFalse($this->cs->isLive('post', '1'));
    }

    public function testIsLiveFalseForNonExistentEntry(): void
    {
        $this->assertFalse($this->cs->isLive('post', 'nonexistent'));
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusIsDraftForFuturePublish(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('+1 hour'));
        $this->assertSame('draft', $this->cs->status('post', '1'));
    }

    public function testStatusIsPublishedWhenLive(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->assertSame('published', $this->cs->status('post', '1'));
    }

    public function testStatusIsExpiredWhenPastExpiry(): void
    {
        $this->db->exec(
            "INSERT INTO content_schedules (entity_type, entity_id, publish_at, expire_at)
             VALUES ('post', '99', '2000-01-01 00:00:00', '2000-01-02 00:00:00')"
        );
        $this->assertSame('expired', $this->cs->status('post', '99'));
    }

    public function testStatusIsCancelledAfterCancel(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->cs->cancel('post', '1');
        $this->assertSame('cancelled', $this->cs->status('post', '1'));
    }

    public function testStatusReturnsNullForNonExistentEntry(): void
    {
        $this->assertNull($this->cs->status('post', 'ghost'));
    }

    // ── listLive ──────────────────────────────────────────────────────────────

    public function testListLiveReturnsPastPublishedEntries(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-2 hours'));
        $this->cs->schedule('post', '2', new \DateTimeImmutable('-1 hour'));
        $this->cs->schedule('post', '3', new \DateTimeImmutable('+1 hour')); // draft
        $live = $this->cs->listLive('post');
        $this->assertCount(2, $live);
    }

    public function testListLiveExcludesCancelled(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->cs->cancel('post', '1');
        $this->assertSame([], $this->cs->listLive('post'));
    }

    public function testListLiveIsEntityTypeScoped(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->cs->schedule('banner', '1', new \DateTimeImmutable('-1 hour'));
        $this->assertCount(1, $this->cs->listLive('post'));
        $this->assertCount(1, $this->cs->listLive('banner'));
    }

    // ── listScheduled ─────────────────────────────────────────────────────────

    public function testListScheduledReturnsFutureEntries(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('+1 hour'));
        $this->cs->schedule('post', '2', new \DateTimeImmutable('+2 hours'));
        $this->cs->schedule('post', '3', new \DateTimeImmutable('-1 hour')); // already live
        $scheduled = $this->cs->listScheduled('post');
        $this->assertCount(2, $scheduled);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelReturnsTrueOnSuccess(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable());
        $this->assertTrue($this->cs->cancel('post', '1'));
    }

    public function testCancelReturnsFalseForNonExistentEntry(): void
    {
        $this->assertFalse($this->cs->cancel('post', 'ghost'));
    }

    public function testCancelIsIdempotentReturnsFalseSecondTime(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable());
        $this->cs->cancel('post', '1');
        $this->assertFalse($this->cs->cancel('post', '1'));
    }

    // ── reschedule ────────────────────────────────────────────────────────────

    public function testRescheduleChangesTiming(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable('-1 hour'));
        $this->assertSame('published', $this->cs->status('post', '1'));

        $this->cs->reschedule('post', '1', new \DateTimeImmutable('+1 hour'));
        $this->assertSame('draft', $this->cs->status('post', '1'));
    }

    public function testRescheduleReturnsFalseForCancelledEntry(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable());
        $this->cs->cancel('post', '1');
        $this->assertFalse($this->cs->reschedule('post', '1', new \DateTimeImmutable('+1 hour')));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesEntry(): void
    {
        $this->cs->schedule('post', '1', new \DateTimeImmutable());
        $this->assertTrue($this->cs->remove('post', '1'));
        $this->assertNull($this->cs->status('post', '1'));
    }

    public function testRemoveReturnsFalseForNonExistentEntry(): void
    {
        $this->assertFalse($this->cs->remove('post', 'ghost'));
    }
}
