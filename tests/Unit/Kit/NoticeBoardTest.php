<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\NoticeBoard;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NoticeBoard.
 */
final class NoticeBoardTest extends TestCase
{
    private PDO $db;
    private NoticeBoard $nb;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE notices (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                body       TEXT         NOT NULL,
                posted_by  VARCHAR(255) NOT NULL,
                is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME     DEFAULT NULL
            )
        ');
        $this->db->exec('
            CREATE TABLE notice_reads (
                notice_id INTEGER      NOT NULL,
                user_id   VARCHAR(255) NOT NULL,
                read_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (notice_id, user_id)
            )
        ');
        $this->nb = new NoticeBoard($this->db);
    }

    // ── post ──────────────────────────────────────────────────────────────────

    public function testPostReturnsId(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testPostStoresBody(): void
    {
        $id  = $this->nb->post('Maintenance tonight', 'admin-1');
        $row = $this->nb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Maintenance tonight', $row['body']);
    }

    public function testPostWithExpiry(): void
    {
        $exp = new \DateTimeImmutable('+1 day');
        $id  = $this->nb->post('Limited notice', 'admin-1', $exp);
        $row = $this->nb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['expires_at']);
    }

    public function testPostThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nb->post('', 'admin-1');
    }

    public function testPostThrowsOnEmptyPostedBy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nb->post('Hello', '');
    }

    // ── deactivate / reactivate ───────────────────────────────────────────────

    public function testDeactivateHidesFromActive(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->assertTrue($this->nb->deactivate($id));
        $this->assertSame([], $this->nb->active());
    }

    public function testDeactivateReturnsFalseIfAlreadyInactive(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->deactivate($id);
        $this->assertFalse($this->nb->deactivate($id));
    }

    public function testReactivateRestoresNotice(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->deactivate($id);
        $this->assertTrue($this->nb->reactivate($id));
        $this->assertCount(1, $this->nb->active());
    }

    public function testReactivateReturnsFalseIfAlreadyActive(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->assertFalse($this->nb->reactivate($id));
    }

    // ── active ────────────────────────────────────────────────────────────────

    public function testActiveReturnsAllActiveNotices(): void
    {
        $this->nb->post('Notice 1', 'admin-1');
        $this->nb->post('Notice 2', 'admin-1');
        $this->assertCount(2, $this->nb->active());
    }

    public function testActiveExcludesExpired(): void
    {
        $past = new \DateTimeImmutable('-1 second');
        $this->nb->post('Expired', 'admin-1', $past);
        $this->nb->post('Active', 'admin-1');
        $this->assertCount(1, $this->nb->active());
    }

    public function testActiveReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->nb->active());
    }

    // ── acknowledge / hasAcknowledged ─────────────────────────────────────────

    public function testAcknowledgeMarksAsRead(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->acknowledge($id, 'user-1');
        $this->assertTrue($this->nb->hasAcknowledged($id, 'user-1'));
    }

    public function testAcknowledgeIsIdempotent(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->acknowledge($id, 'user-1');
        $this->nb->acknowledge($id, 'user-1'); // should not throw
        $this->assertSame(1, $this->nb->acknowledgeCount($id));
    }

    public function testHasAcknowledgedReturnsFalseForOtherUser(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->acknowledge($id, 'user-1');
        $this->assertFalse($this->nb->hasAcknowledged($id, 'user-2'));
    }

    public function testAcknowledgeThrowsOnEmptyUserId(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->expectException(\InvalidArgumentException::class);
        $this->nb->acknowledge($id, '');
    }

    // ── unread ────────────────────────────────────────────────────────────────

    public function testUnreadReturnsUnacknowledgedNotices(): void
    {
        $id1 = $this->nb->post('Notice 1', 'admin-1');
        $id2 = $this->nb->post('Notice 2', 'admin-1');
        $this->nb->acknowledge($id1, 'user-1');
        $unread = $this->nb->unread('user-1');
        $this->assertCount(1, $unread);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$unread[0]['id']);
    }

    public function testUnreadExcludesExpiredNotices(): void
    {
        $past = new \DateTimeImmutable('-1 second');
        $this->nb->post('Expired', 'admin-1', $past);
        $this->assertSame([], $this->nb->unread('user-1'));
    }

    public function testUnreadThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->nb->unread('');
    }

    // ── acknowledgeCount ──────────────────────────────────────────────────────

    public function testAcknowledgeCountReturnsCorrectCount(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->acknowledge($id, 'user-1');
        $this->nb->acknowledge($id, 'user-2');
        $this->assertSame(2, $this->nb->acknowledgeCount($id));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesNotice(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->assertTrue($this->nb->remove($id));
        $this->assertNull($this->nb->find($id));
    }

    public function testRemoveDeletesReadRecords(): void
    {
        $id = $this->nb->post('Hello', 'admin-1');
        $this->nb->acknowledge($id, 'user-1');
        $this->nb->remove($id);
        // After removal, count should be 0
        $this->assertSame(0, $this->nb->acknowledgeCount($id));
    }

    public function testRemoveReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->nb->remove(9999));
    }
}
