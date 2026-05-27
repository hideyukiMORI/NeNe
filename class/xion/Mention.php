<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Mention — track @-mentions of users within content items.
 *
 * Records which users are mentioned in a given piece of content (post,
 * comment, document). Supports listing unread mentions per user and
 * bulk-marking as read.
 *
 * ## Usage
 *
 * ```php
 * $m = new Mention($pdo);
 *
 * // When saving a comment, record mentions
 * $m->record('comment', '99', 'author-1', ['user-2', 'user-3']);
 *
 * // User inbox
 * $unread = $m->unreadFor('user-2');
 * $m->markRead('user-2', array_column($unread, 'id'));
 *
 * // Content mentions
 * $who = $m->mentionedIn('comment', '99');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE mentions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     author_id    VARCHAR(255) NOT NULL,
 *     mentioned_id VARCHAR(255) NOT NULL,
 *     read_at      DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, mentioned_id)
 * );
 * ```
 */
final class Mention
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record mentions for a piece of content.
     *
     * Idempotent: re-recording the same mention for an existing (entity, user)
     * pair is silently ignored so callers can re-save content safely.
     *
     * @param  list<string> $mentionedIds  User IDs that were @-mentioned.
     * @return int Number of new mention rows inserted.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function record(
        string $entityType,
        string $entityId,
        string $authorId,
        array $mentionedIds
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $authorId = trim($authorId);
        if ($authorId === '') {
            throw new \InvalidArgumentException('author_id must not be empty.');
        }
        if ($mentionedIds === []) {
            return 0;
        }

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

        $stmt = $this->db()->prepare(
            "{$ignore} INTO mentions (entity_type, entity_id, author_id, mentioned_id)
             VALUES (:type, :eid, :author, :mid)"
        );

        $inserted = 0;
        foreach ($mentionedIds as $mid) {
            $mid = trim((string)$mid);
            if ($mid === '') {
                continue;
            }
            $stmt->execute([
                ':type'   => $entityType,
                ':eid'    => $entityId,
                ':author' => $authorId,
                ':mid'    => $mid,
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * List all unread mentions for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function unreadFor(string $userId, int $limit = 50): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, author_id, mentioned_id, read_at, created_at
             FROM mentions
             WHERE mentioned_id = :uid AND read_at IS NULL
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all mentions for a user (read and unread), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function allFor(string $userId, int $limit = 50): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, author_id, mentioned_id, read_at, created_at
             FROM mentions
             WHERE mentioned_id = :uid
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark a list of mention IDs as read for a user.
     *
     * Only marks rows that belong to the given user.
     *
     * @param  list<int> $ids
     * @return int Number of rows updated.
     */
    public function markRead(string $userId, array $ids): int
    {
        $userId = trim($userId);
        if ($userId === '' || $ids === []) {
            return 0;
        }
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt        = $this->db()->prepare(
            "UPDATE mentions SET read_at = ?
             WHERE mentioned_id = ? AND read_at IS NULL AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$now, $userId], array_values($ids)));
        return $stmt->rowCount();
    }

    /**
     * Mark all unread mentions for a user as read.
     *
     * @return int Number of rows updated.
     */
    public function markAllRead(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE mentions SET read_at = :now
             WHERE mentioned_id = :uid AND read_at IS NULL'
        );
        $stmt->execute([':now' => $now, ':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Count unread mentions for a user.
     */
    public function unreadCount(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM mentions WHERE mentioned_id = :uid AND read_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List user IDs mentioned in a piece of content.
     *
     * @return list<string>
     */
    public function mentionedIn(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT mentioned_id FROM mentions
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Delete all mentions for a piece of content (e.g. when the content is deleted).
     *
     * @return int Number of rows deleted.
     */
    public function deleteForEntity(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM mentions WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validateEntity(string $entityType, string $entityId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$entityType, $entityId];
    }
}
