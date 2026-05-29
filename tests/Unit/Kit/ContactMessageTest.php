<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ContactMessage;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContactMessageTest extends TestCase
{
    private PDO $pdo;
    private ContactMessage $cm;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE contact_messages (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         VARCHAR(255) NOT NULL,
                email        VARCHAR(255) NOT NULL,
                subject      VARCHAR(500) NOT NULL,
                body         TEXT         NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'unread\',
                ip_address   VARCHAR(45)  NULL,
                replied_at   DATETIME     NULL,
                submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cm = new ContactMessage($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->cm->submit('Alice', 'alice@example.com', 'Help!', 'I cannot log in.');
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresFields(): void
    {
        $id  = $this->cm->submit('Alice', 'alice@example.com', 'Help!', 'I cannot log in.', '1.2.3.4');
        $row = $this->cm->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Alice', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('alice@example.com', $row['email']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContactMessage::STATUS_UNREAD, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip_address']);
    }

    public function testSubmitThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->submit('', 'alice@example.com', 'Help!', 'Body');
    }

    public function testSubmitThrowsOnEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->submit('Alice', '', 'Help!', 'Body');
    }

    public function testSubmitThrowsOnEmptySubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->submit('Alice', 'alice@example.com', '', 'Body');
    }

    public function testSubmitThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cm->submit('Alice', 'alice@example.com', 'Help!', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->cm->get(9999));
    }

    // ── inbox ─────────────────────────────────────────────────────────────────

    public function testInboxReturnsNonArchivedMessages(): void
    {
        $id1 = $this->cm->submit('Alice', 'a@x.com', 'Q1', 'Body1');
        $id2 = $this->cm->submit('Bob', 'b@x.com', 'Q2', 'Body2');
        $this->cm->archive($id1);
        $inbox = $this->cm->inbox();
        $this->assertCount(1, $inbox);
        $this->assertSame((string)$id2, (string)$inbox[0]['id']);
    }

    public function testInboxReturnsNewestFirst(): void
    {
        $id1 = $this->cm->submit('Alice', 'a@x.com', 'Q1', 'Body1');
        $id2 = $this->cm->submit('Bob', 'b@x.com', 'Q2', 'Body2');
        $inbox = $this->cm->inbox();
        $this->assertSame((string)$id2, (string)$inbox[0]['id']);
    }

    public function testInboxRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->cm->submit('User' . $i, "u{$i}@x.com", "Q{$i}", "Body{$i}");
        }
        $page1 = $this->cm->inbox(3, 0);
        $page2 = $this->cm->inbox(3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── unreadCount ───────────────────────────────────────────────────────────

    public function testUnreadCountReturnsCorrectCount(): void
    {
        $id1 = $this->cm->submit('Alice', 'a@x.com', 'Q1', 'Body1');
        $this->cm->submit('Bob', 'b@x.com', 'Q2', 'Body2');
        $this->cm->markRead($id1);
        $this->assertSame(1, $this->cm->unreadCount());
    }

    // ── markRead ──────────────────────────────────────────────────────────────

    public function testMarkReadChangesStatus(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->assertTrue($this->cm->markRead($id));
        $row = $this->cm->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContactMessage::STATUS_READ, $row['status']);
    }

    public function testMarkReadReturnsFalseWhenAlreadyRead(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->cm->markRead($id);
        $this->assertFalse($this->cm->markRead($id));
    }

    // ── markReplied ───────────────────────────────────────────────────────────

    public function testMarkRepliedChangesStatus(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->assertTrue($this->cm->markReplied($id));
        $row = $this->cm->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContactMessage::STATUS_REPLIED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['replied_at']);
    }

    // ── archive ───────────────────────────────────────────────────────────────

    public function testArchiveChangesStatus(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->assertTrue($this->cm->archive($id));
        $row = $this->cm->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ContactMessage::STATUS_ARCHIVED, $row['status']);
    }

    public function testArchiveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cm->archive(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesMessage(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->assertTrue($this->cm->delete($id));
        $this->assertNull($this->cm->get($id));
    }

    // ── purgeArchived ─────────────────────────────────────────────────────────

    public function testPurgeArchivedDeletesOldArchivedMessages(): void
    {
        $id = $this->cm->submit('Alice', 'a@x.com', 'Q', 'Body');
        $this->cm->archive($id);
        $this->pdo->exec("UPDATE contact_messages SET submitted_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $this->cm->submit('Bob', 'b@x.com', 'Q2', 'Body2');
        $deleted = $this->cm->purgeArchived('2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->cm->inbox());
    }
}
