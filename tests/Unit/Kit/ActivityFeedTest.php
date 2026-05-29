<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ActivityFeed;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ActivityFeed.
 */
final class ActivityFeedTest extends TestCase
{
    private PDO $db;
    private ActivityFeed $feed;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE activity_feed (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                type         VARCHAR(100) NOT NULL,
                subject_type VARCHAR(100) NOT NULL DEFAULT \'\',
                subject_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                data         TEXT         NOT NULL DEFAULT \'{}\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->feed = new ActivityFeed($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsIntId(): void
    {
        $id = $this->feed->record('user-1', 'liked_post');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->feed->record('user-1', '');
    }

    public function testRecordStoresSubjectFields(): void
    {
        $this->feed->record('user-1', 'liked_post', 'post', '42');
        $events = $this->feed->fetch('user-1');
        $this->assertSame('post', $events[0]['subject_type']);
        $this->assertSame('42', $events[0]['subject_id']);
    }

    public function testRecordStoresJsonData(): void
    {
        $this->feed->record('user-1', 'liked_post', data: ['title' => 'Hello']);
        $events = $this->feed->fetch('user-1');
        $this->assertSame(['title' => 'Hello'], $events[0]['data']);
    }

    // ── fetch ─────────────────────────────────────────────────────────────────

    public function testFetchReturnsEventsNewestFirst(): void
    {
        $id1 = $this->feed->record('user-1', 'a');
        $id2 = $this->feed->record('user-1', 'b');
        $events = $this->feed->fetch('user-1');
        $this->assertSame((string)$id2, (string)$events[0]['id']);
        $this->assertSame((string)$id1, (string)$events[1]['id']);
    }

    public function testFetchRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->feed->record('user-1', 'evt');
        }
        $this->assertCount(3, $this->feed->fetch('user-1', limit: 3));
    }

    public function testFetchClampsLimitAt100(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->feed->record('user-1', 'evt');
        }
        $this->assertCount(5, $this->feed->fetch('user-1', limit: 200));
    }

    public function testFetchFilterByType(): void
    {
        $this->feed->record('user-1', 'liked_post');
        $this->feed->record('user-1', 'earned_badge');
        $this->feed->record('user-1', 'liked_post');

        $events = $this->feed->fetch('user-1', type: 'liked_post');
        $this->assertCount(2, $events);
    }

    public function testFetchBeforeIdCursor(): void
    {
        $id1 = $this->feed->record('user-1', 'a');
        $id2 = $this->feed->record('user-1', 'b');
        $id3 = $this->feed->record('user-1', 'c');

        $events = $this->feed->fetch('user-1', beforeId: $id3);
        $this->assertCount(2, $events);
        $this->assertSame((string)$id2, (string)$events[0]['id']);
        $this->assertSame((string)$id1, (string)$events[1]['id']);
    }

    public function testFetchIsUserScoped(): void
    {
        $this->feed->record('user-1', 'a');
        $this->feed->record('user-2', 'b');
        $this->assertCount(1, $this->feed->fetch('user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForNewUser(): void
    {
        $this->assertSame(0, $this->feed->count('user-1'));
    }

    public function testCountReturnsCorrectTotal(): void
    {
        $this->feed->record('user-1', 'a');
        $this->feed->record('user-1', 'b');
        $this->assertSame(2, $this->feed->count('user-1'));
    }

    public function testCountFilterByType(): void
    {
        $this->feed->record('user-1', 'liked');
        $this->feed->record('user-1', 'liked');
        $this->feed->record('user-1', 'badge');
        $this->assertSame(2, $this->feed->count('user-1', type: 'liked'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanThrowsOnNonPositiveDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->feed->purgeOlderThan('user-1', 0);
    }

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        // Insert a fake old event
        $this->db->exec(
            "INSERT INTO activity_feed (user_id, type, created_at)
             VALUES ('user-1', 'old_event', '2000-01-01 00:00:00')"
        );
        $this->feed->record('user-1', 'new_event');

        $purged = $this->feed->purgeOlderThan('user-1', days: 1);
        $this->assertSame(1, $purged);
        $this->assertSame(1, $this->feed->count('user-1'));
    }
}
