<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ChatMessage — simple chat room message store with soft-delete.
 *
 * Provides a persistent message log per room. Messages can be soft-deleted
 * (body cleared, deleted_at set) by their sender. Rooms are created implicitly
 * — no separate room setup is required.
 *
 * ## Usage
 *
 * ```php
 * $chat = new ChatMessage($pdo);
 *
 * // Send a message
 * $id = $chat->send('room-general', 'user-1', 'Hello!');
 *
 * // Fetch recent messages (newest last)
 * $chat->recent('room-general', 50);
 *
 * // Fetch messages before a given ID (for pagination)
 * $chat->recent('room-general', 20, beforeId: 100);
 *
 * // Soft-delete your own message
 * $chat->delete($id, 'user-1');
 *
 * // Count
 * $chat->count('room-general');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE chat_messages (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     room_id    VARCHAR(255) NOT NULL,
 *     sender_id  VARCHAR(255) NOT NULL,
 *     body       TEXT         NOT NULL DEFAULT '',
 *     deleted_at DATETIME     DEFAULT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ChatMessage
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Send a message to a room.
     *
     * @return int The new message ID.
     * @throws \InvalidArgumentException if room_id, sender_id, or body is empty.
     */
    public function send(string $roomId, string $senderId, string $body): int
    {
        $roomId   = $this->validateRoom($roomId);
        $senderId = $this->validateSender($senderId);
        $body     = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('message body must not be empty.');
        }
        $db = $this->db();
        $db->prepare(
            'INSERT INTO chat_messages (room_id, sender_id, body) VALUES (:room, :sender, :body)'
        )->execute([':room' => $roomId, ':sender' => $senderId, ':body' => $body]);
        return (int)$db->lastInsertId();
    }

    /**
     * Soft-delete a message (sender only).
     *
     * Clears the body and sets deleted_at. Returns false if the message
     * does not exist, is already deleted, or belongs to a different sender.
     */
    public function delete(int $messageId, string $senderId): bool
    {
        $senderId = $this->validateSender($senderId);
        $stmt     = $this->db()->prepare(
            'UPDATE chat_messages
             SET body = \'\', deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND sender_id = :sender AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $messageId, ':sender' => $senderId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a message by ID (including deleted messages).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $messageId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, room_id, sender_id, body, deleted_at, created_at
             FROM chat_messages WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch recent messages for a room, ordered oldest-first.
     *
     * @param int      $limit    Maximum number of messages to return (1–200).
     * @param int|null $beforeId Return only messages with id < beforeId (for backward pagination).
     * @return list<array<string,mixed>>
     */
    public function recent(string $roomId, int $limit = 50, ?int $beforeId = null): array
    {
        $roomId = $this->validateRoom($roomId);
        $limit  = max(1, min(200, $limit));

        if ($beforeId !== null) {
            $stmt = $this->db()->prepare(
                "SELECT id, room_id, sender_id, body, deleted_at, created_at
                 FROM chat_messages
                 WHERE room_id = :room AND id < :bid
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':room' => $roomId, ':bid' => $beforeId]);
        } else {
            $stmt = $this->db()->prepare(
                "SELECT id, room_id, sender_id, body, deleted_at, created_at
                 FROM chat_messages
                 WHERE room_id = :room
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':room' => $roomId]);
        }

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Count messages in a room (including deleted).
     */
    public function count(string $roomId): int
    {
        $roomId = $this->validateRoom($roomId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM chat_messages WHERE room_id = :room'
        );
        $stmt->execute([':room' => $roomId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Hard-delete all messages in a room.
     *
     * @return int Number of rows deleted.
     */
    public function purgeRoom(string $roomId): int
    {
        $roomId = $this->validateRoom($roomId);
        $stmt   = $this->db()->prepare('DELETE FROM chat_messages WHERE room_id = :room');
        $stmt->execute([':room' => $roomId]);
        return $stmt->rowCount();
    }

    /**
     * Purge messages older than N days (hard delete).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM chat_messages WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateRoom(string $roomId): string
    {
        $roomId = trim($roomId);
        if ($roomId === '') {
            throw new \InvalidArgumentException('room_id must not be empty.');
        }
        return $roomId;
    }

    private function validateSender(string $senderId): string
    {
        $senderId = trim($senderId);
        if ($senderId === '') {
            throw new \InvalidArgumentException('sender_id must not be empty.');
        }
        return $senderId;
    }
}
