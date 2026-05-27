<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\NotificationInbox;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationInbox using an in-memory SQLite database.
 */
final class NotificationInboxTest extends TestCase
{
    private PDO $db;
    private NotificationInbox $inbox;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE notifications (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                type       VARCHAR(64)  NOT NULL,
                title      VARCHAR(255) NOT NULL,
                body       TEXT         NOT NULL DEFAULT \'\',
                read_at    DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->inbox = new NotificationInbox($this->db);
    }

    // ── push ─────────────────────────────────────────────────────────────────

    public function testPushReturnsId(): void
    {
        $id = $this->inbox->push('user:1', 'order.shipped', 'Shipped!');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testPushedNotificationIsUnread(): void
    {
        $this->inbox->push('user:1', 'order.shipped', 'Shipped!');
        $items = $this->inbox->list('user:1');
        $this->assertFalse($items[0]['read']);
        $this->assertNull($items[0]['read_at']);
    }

    public function testPushWithBody(): void
    {
        $this->inbox->push('user:1', 'order.shipped', 'Shipped!', 'Tracking: ABC123');
        $items = $this->inbox->list('user:1');
        $this->assertSame('Tracking: ABC123', $items[0]['body']);
    }

    public function testPushWithIntUserId(): void
    {
        $this->inbox->push(42, 'alert', 'Alert');
        $items = $this->inbox->list(42);
        $this->assertCount(1, $items);
    }

    // ── list ─────────────────────────────────────────────────────────────────

    public function testListReturnsNewestFirst(): void
    {
        $id1 = $this->inbox->push('user:1', 'a', 'First');
        $id2 = $this->inbox->push('user:1', 'b', 'Second');
        $items = $this->inbox->list('user:1');
        $this->assertSame($id2, $items[0]['id']);
        $this->assertSame($id1, $items[1]['id']);
    }

    public function testListReturnsOnlyOwnerItems(): void
    {
        $this->inbox->push('user:1', 'a', 'For user 1');
        $this->inbox->push('user:2', 'b', 'For user 2');
        $items = $this->inbox->list('user:1');
        $this->assertCount(1, $items);
        $this->assertSame('For user 1', $items[0]['title']);
    }

    public function testListEmptyForNewUser(): void
    {
        $this->assertEmpty($this->inbox->list('user:99'));
    }

    public function testListUnreadOnlyFiltersRead(): void
    {
        $id = $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->push('user:1', 'b', 'B');
        $this->inbox->markRead($id, 'user:1');

        $unread = $this->inbox->list('user:1', unreadOnly: true);
        $this->assertCount(1, $unread);
        $this->assertSame('B', $unread[0]['title']);
    }

    public function testListItemHasExpectedFields(): void
    {
        $this->inbox->push('user:1', 'order.shipped', 'Title', 'Body');
        $items = $this->inbox->list('user:1');
        $item  = $items[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('body', $item);
        $this->assertArrayHasKey('read', $item);
        $this->assertArrayHasKey('read_at', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    // ── markRead ─────────────────────────────────────────────────────────────

    public function testMarkReadReturnsTrueAndSetsReadAt(): void
    {
        $id = $this->inbox->push('user:1', 'a', 'A');
        $result = $this->inbox->markRead($id, 'user:1');
        $this->assertTrue($result);

        $items = $this->inbox->list('user:1');
        $this->assertTrue($items[0]['read']);
        $this->assertNotNull($items[0]['read_at']);
    }

    public function testMarkReadIsIdempotent(): void
    {
        $id = $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->markRead($id, 'user:1');
        $firstReadAt = $this->inbox->list('user:1')[0]['read_at'];

        // Small sleep to ensure time would differ if read_at were overwritten
        usleep(1000);
        $this->inbox->markRead($id, 'user:1');
        $secondReadAt = $this->inbox->list('user:1')[0]['read_at'];

        $this->assertSame($firstReadAt, $secondReadAt);
    }

    public function testMarkReadWrongUserReturnsFalse(): void
    {
        $id = $this->inbox->push('user:1', 'a', 'A');
        $result = $this->inbox->markRead($id, 'user:999');
        $this->assertFalse($result);
    }

    public function testMarkReadNotFoundReturnsFalse(): void
    {
        $result = $this->inbox->markRead(9999, 'user:1');
        $this->assertFalse($result);
    }

    // ── markAllRead ───────────────────────────────────────────────────────────

    public function testMarkAllReadReturnsCount(): void
    {
        $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->push('user:1', 'b', 'B');
        $count = $this->inbox->markAllRead('user:1');
        $this->assertSame(2, $count);
    }

    public function testMarkAllReadIsIdempotent(): void
    {
        $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->markAllRead('user:1');
        $count = $this->inbox->markAllRead('user:1');
        $this->assertSame(0, $count);
    }

    public function testMarkAllReadDoesNotAffectOtherUsers(): void
    {
        $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->push('user:2', 'b', 'B');
        $this->inbox->markAllRead('user:1');

        $this->assertSame(0, $this->inbox->unreadCount('user:1'));
        $this->assertSame(1, $this->inbox->unreadCount('user:2'));
    }

    // ── unreadCount ───────────────────────────────────────────────────────────

    public function testUnreadCountReturnsCorrectNumber(): void
    {
        $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->push('user:1', 'b', 'B');
        $id = $this->inbox->push('user:1', 'c', 'C');
        $this->inbox->markRead($id, 'user:1');

        $this->assertSame(2, $this->inbox->unreadCount('user:1'));
    }

    public function testUnreadCountIsZeroForNewUser(): void
    {
        $this->assertSame(0, $this->inbox->unreadCount('user:99'));
    }

    public function testUnreadCountDecreasesAfterMarkAllRead(): void
    {
        $this->inbox->push('user:1', 'a', 'A');
        $this->inbox->push('user:1', 'b', 'B');
        $this->assertSame(2, $this->inbox->unreadCount('user:1'));

        $this->inbox->markAllRead('user:1');
        $this->assertSame(0, $this->inbox->unreadCount('user:1'));
    }
}
