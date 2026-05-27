<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Announcement;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnnouncementTest extends TestCase
{
    private PDO $db;
    private Announcement $ann;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE announcements (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                body       TEXT        NOT NULL DEFAULT \'\',
                category   VARCHAR(50) NOT NULL DEFAULT \'info\',
                publish_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expire_at  DATETIME    DEFAULT NULL,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE announcement_dismissals (
                announcement_id INTEGER      NOT NULL,
                user_id         VARCHAR(255) NOT NULL,
                dismissed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (announcement_id, user_id)
            )
        ');
        $this->ann = new Announcement($this->db);
    }

    public function testPublishReturnsId(): void
    {
        $id = $this->ann->publish('Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testPublishThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ann->publish('');
    }

    public function testPublishThrowsWhenExpireBeforePublish(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $pub = new \DateTimeImmutable('+1 hour');
        $exp = new \DateTimeImmutable('+30 minutes');
        $this->ann->publish('x', 'info', $pub, $exp);
    }

    public function testFind(): void
    {
        $id  = $this->ann->publish('Maintenance', 'warning');
        $row = $this->ann->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Maintenance', $row['body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('warning', $row['category']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->ann->find(999));
    }

    public function testActiveReturnsLiveAnnouncements(): void
    {
        $this->ann->publish('Live now');
        $rows = $this->ann->active('user-1');
        $this->assertCount(1, $rows);
        $this->assertSame('Live now', $rows[0]['body']);
    }

    public function testActiveExcludesScheduledAnnouncements(): void
    {
        $this->ann->publish('Future', 'info', new \DateTimeImmutable('+1 hour'));
        $this->assertSame([], $this->ann->active('user-1'));
    }

    public function testActiveExcludesExpiredAnnouncements(): void
    {
        $this->db->exec(
            "INSERT INTO announcements (body, publish_at, expire_at)
             VALUES ('old', '2000-01-01 00:00:00', '2000-01-02 00:00:00')"
        );
        $this->assertSame([], $this->ann->active('user-1'));
    }

    public function testActiveExcludesDismissedAnnouncements(): void
    {
        $id = $this->ann->publish('Alert');
        $this->ann->dismiss($id, 'user-1');
        $this->assertSame([], $this->ann->active('user-1'));
        $this->assertCount(1, $this->ann->active('user-2'));
    }

    public function testDismissIsIdempotent(): void
    {
        $id = $this->ann->publish('Alert');
        $this->ann->dismiss($id, 'user-1');
        $this->ann->dismiss($id, 'user-1');
        $this->assertTrue($this->ann->isDismissed($id, 'user-1'));
    }

    public function testIsDismissedReturnsFalseBeforeDismissal(): void
    {
        $id = $this->ann->publish('Alert');
        $this->assertFalse($this->ann->isDismissed($id, 'user-1'));
    }

    public function testExpire(): void
    {
        $id = $this->ann->publish('Live');
        $this->assertTrue($this->ann->expire($id));
        $this->assertSame([], $this->ann->active('user-1'));
    }

    public function testExpireReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ann->expire(999));
    }

    public function testDelete(): void
    {
        $id = $this->ann->publish('Live');
        $this->ann->dismiss($id, 'user-1');
        $this->assertTrue($this->ann->delete($id));
        $this->assertNull($this->ann->find($id));
        $this->assertSame(0, $this->ann->count());
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ann->delete(999));
    }

    public function testCount(): void
    {
        $this->assertSame(0, $this->ann->count());
        $this->ann->publish('a');
        $this->ann->publish('b');
        $this->assertSame(2, $this->ann->count());
    }
}
