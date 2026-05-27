<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * User notification inbox: push, list, mark-as-read, unread count.
 *
 * Uses `read_at` nullable timestamp instead of `is_read` boolean —
 * preserves *when* the notification was read and is directly indexable.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE notifications (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     type       VARCHAR(64)  NOT NULL,
 *     title      VARCHAR(255) NOT NULL,
 *     body       TEXT         NOT NULL DEFAULT '',
 *     read_at    DATETIME     DEFAULT NULL,   -- NULL = unread
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_notifications_user ON notifications (user_id, id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $inbox = new NotificationInbox();
 *
 * // Push a notification (e.g. from a service after a user action)
 * $inbox->push($userId, 'order.shipped', 'Your order has shipped', 'Tracking: 1Z999...');
 *
 * // List all notifications for a user (newest first)
 * $items = $inbox->list($userId);
 *
 * // List unread only
 * $unread = $inbox->list($userId, unreadOnly: true);
 *
 * // Mark one as read (idempotent, owner-enforced)
 * $ok = $inbox->markRead($notificationId, $userId);
 *
 * // Mark all as read
 * $marked = $inbox->markAllRead($userId);
 *
 * // Unread count (for badge)
 * $count = $inbox->unreadCount($userId);
 * ```
 */
final class NotificationInbox
{
    private const TABLE = 'notifications';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Push a notification to a user's inbox.
     *
     * @param  string|int $userId Application-level user identifier.
     * @param  string     $type   Machine-readable event type (e.g. 'order.shipped').
     * @param  string     $title  Short human-readable title.
     * @param  string     $body   Optional longer body text.
     * @return int        New notification row ID.
     */
    public function push(
        string|int $userId,
        string $type,
        string $title,
        string $body = '',
    ): int {
        $db        = $this->db ?? PdoConnection::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (user_id, type, title, body, created_at)' .
            ' VALUES (:u, :t, :ti, :b, :c)'
        );
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->bindValue(':t', $type, PDO::PARAM_STR);
        $stmt->bindValue(':ti', $title, PDO::PARAM_STR);
        $stmt->bindValue(':b', $body, PDO::PARAM_STR);
        $stmt->bindValue(':c', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * List notifications for a user, newest first (ORDER BY id DESC).
     *
     * `id DESC` is used instead of `created_at DESC` for stable ordering
     * when multiple notifications are inserted within the same second.
     *
     * @param  string|int $userId     Application-level user identifier.
     * @param  bool       $unreadOnly Return only unread (read_at IS NULL) if true.
     * @return list<array{id:int,type:string,title:string,body:string,read:bool,read_at:string|null,created_at:string}>
     */
    public function list(string|int $userId, bool $unreadOnly = false): array
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $sql = 'SELECT id, type, title, body, read_at, created_at' .
               ' FROM ' . $this->table .
               ' WHERE user_id = :u';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'         => (int)$r['id'],
            'type'       => (string)$r['type'],
            'title'      => (string)$r['title'],
            'body'       => (string)$r['body'],
            'read'       => $r['read_at'] !== null,
            'read_at'    => $r['read_at'] !== null ? (string)$r['read_at'] : null,
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }

    /**
     * Mark a single notification as read. Idempotent: preserves original read_at.
     *
     * Returns true if the notification was found for this user, false otherwise.
     * Cross-user access silently returns false (not 403) to prevent ID enumeration.
     *
     * @param int         $notificationId Row ID.
     * @param string|int  $userId         Must match the notification's owner.
     */
    public function markRead(int $notificationId, string|int $userId): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();

        // Check ownership first
        $stmt = $db->prepare(
            'SELECT id, read_at FROM ' . $this->table .
            ' WHERE id = :id AND user_id = :u LIMIT 1'
        );
        $stmt->bindValue(':id', $notificationId, PDO::PARAM_INT);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return false;
        }

        // Already read — preserve original read_at
        if ($row['read_at'] !== null) {
            return true;
        }

        $readAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt   = $db->prepare(
            'UPDATE ' . $this->table . ' SET read_at = :t WHERE id = :id'
        );
        $stmt->bindValue(':t', $readAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $notificationId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Mark all unread notifications as read for a user.
     *
     * Returns the number of notifications that were marked (0 if all were already read).
     *
     * @param string|int $userId Application-level user identifier.
     */
    public function markAllRead(string|int $userId): int
    {
        $db     = $this->db ?? PdoConnection::getInstance();
        $readAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET read_at = :t WHERE user_id = :u AND read_at IS NULL'
        );
        $stmt->bindValue(':t', $readAt, PDO::PARAM_STR);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Count unread notifications for a user (for badge display).
     *
     * @param string|int $userId Application-level user identifier.
     */
    public function unreadCount(string|int $userId): int
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table .
            ' WHERE user_id = :u AND read_at IS NULL'
        );
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
