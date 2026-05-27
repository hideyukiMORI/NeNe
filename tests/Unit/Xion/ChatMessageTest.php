<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ChatMessage;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatMessage.
 */
final class ChatMessageTest extends TestCase
{
    private PDO $db;
    private ChatMessage $chat;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE chat_messages (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id    VARCHAR(255) NOT NULL,
                sender_id  VARCHAR(255) NOT NULL,
                body       TEXT         NOT NULL DEFAULT \'\',
                deleted_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->chat = new ChatMessage($this->db);
    }

    // ── send ──────────────────────────────────────────────────────────────────

    public function testSendReturnsId(): void
    {
        $id = $this->chat->send('room-1', 'user-1', 'Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testSendThrowsOnEmptyRoom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chat->send('', 'user-1', 'Hello!');
    }

    public function testSendThrowsOnEmptySender(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chat->send('room-1', '', 'Hello!');
    }

    public function testSendThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chat->send('room-1', 'user-1', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsMessage(): void
    {
        $id  = $this->chat->send('room-1', 'user-1', 'Hi');
        $msg = $this->chat->find($id);
        $this->assertNotNull($msg);
        $this->assertSame('Hi', $msg['body']);
        $this->assertSame('user-1', $msg['sender_id']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->chat->find(999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteSoftDeletesMessage(): void
    {
        $id = $this->chat->send('room-1', 'user-1', 'Hello!');
        $this->assertTrue($this->chat->delete($id, 'user-1'));
        $msg = $this->chat->find($id);
        $this->assertNotNull($msg);
        $this->assertSame('', $msg['body']);
        $this->assertNotNull($msg['deleted_at']);
    }

    public function testDeleteReturnsFalseForWrongSender(): void
    {
        $id = $this->chat->send('room-1', 'user-1', 'Hello!');
        $this->assertFalse($this->chat->delete($id, 'user-2'));
        $msg = $this->chat->find($id);
        $this->assertSame('Hello!', $msg['body']);
    }

    public function testDeleteReturnsFalseForAlreadyDeleted(): void
    {
        $id = $this->chat->send('room-1', 'user-1', 'Hello!');
        $this->chat->delete($id, 'user-1');
        $this->assertFalse($this->chat->delete($id, 'user-1'));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsMessagesOldestFirst(): void
    {
        $this->chat->send('room-1', 'user-1', 'First');
        $this->chat->send('room-1', 'user-1', 'Second');
        $this->chat->send('room-1', 'user-1', 'Third');
        $msgs = $this->chat->recent('room-1', 10);
        $this->assertCount(3, $msgs);
        $this->assertSame('First', $msgs[0]['body']);
        $this->assertSame('Third', $msgs[2]['body']);
    }

    public function testRecentRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->chat->send('room-1', 'user-1', "msg-{$i}");
        }
        $msgs = $this->chat->recent('room-1', 3);
        $this->assertCount(3, $msgs);
        $this->assertSame('msg-3', $msgs[0]['body']);
    }

    public function testRecentWithBeforeId(): void
    {
        $id1 = $this->chat->send('room-1', 'user-1', 'First');
        $this->chat->send('room-1', 'user-1', 'Second');
        $id3 = $this->chat->send('room-1', 'user-1', 'Third');
        $msgs = $this->chat->recent('room-1', 10, $id3);
        $this->assertCount(2, $msgs);
        $this->assertSame('First', $msgs[0]['body']);
        $this->assertSame('Second', $msgs[1]['body']);
    }

    public function testRecentReturnsEmptyForEmptyRoom(): void
    {
        $this->assertSame([], $this->chat->recent('empty-room'));
    }

    public function testRecentIsolatesRooms(): void
    {
        $this->chat->send('room-1', 'user-1', 'Room 1 msg');
        $this->chat->send('room-2', 'user-1', 'Room 2 msg');
        $msgs = $this->chat->recent('room-1');
        $this->assertCount(1, $msgs);
        $this->assertSame('Room 1 msg', $msgs[0]['body']);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->chat->count('room-1'));
        $this->chat->send('room-1', 'user-1', 'a');
        $this->chat->send('room-1', 'user-1', 'b');
        $this->assertSame(2, $this->chat->count('room-1'));
    }

    public function testCountIncludesDeletedMessages(): void
    {
        $id = $this->chat->send('room-1', 'user-1', 'Hello');
        $this->chat->delete($id, 'user-1');
        $this->assertSame(1, $this->chat->count('room-1'));
    }

    // ── purgeRoom ─────────────────────────────────────────────────────────────

    public function testPurgeRoom(): void
    {
        $this->chat->send('room-1', 'user-1', 'a');
        $this->chat->send('room-1', 'user-1', 'b');
        $this->chat->send('room-2', 'user-1', 'c');
        $deleted = $this->chat->purgeRoom('room-1');
        $this->assertSame(2, $deleted);
        $this->assertSame(0, $this->chat->count('room-1'));
        $this->assertSame(1, $this->chat->count('room-2'));
    }
}
