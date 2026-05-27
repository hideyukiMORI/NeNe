<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * CommentThread — threaded comments on any entity (posts, products, tickets…).
 *
 * Supports top-level comments and single-level replies (parent_id).
 * Comments can be soft-deleted (body replaced, deleted_at set) or hard-deleted.
 * The thread structure is kept intact — deleted comments show as tombstones.
 *
 * Status lifecycle: created → (edited) → soft-deleted
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE comments (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     parent_id   INTEGER      DEFAULT NULL,
 *     body        TEXT         NOT NULL,
 *     deleted_at  DATETIME     DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class CommentThread
{
    private const DELETED_BODY = '[deleted]';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Post a top-level comment on an entity.
     *
     * @throws \InvalidArgumentException if any required field is empty.
     */
    public function post(string $entityType, string $entityId, string $userId, string $body): int
    {
        return $this->insert($entityType, $entityId, $userId, $body, null);
    }

    /**
     * Reply to an existing comment.
     *
     * @throws \InvalidArgumentException if any required field is empty.
     * @throws \RuntimeException         if the parent comment does not exist or is deleted.
     */
    public function reply(
        string $entityType,
        string $entityId,
        string $userId,
        string $body,
        int $parentId
    ): int {
        $parent = $this->getRow($parentId);
        if ($parent === null) {
            throw new \RuntimeException("Parent comment {$parentId} does not exist.");
        }
        if ($parent['deleted_at'] !== null) {
            throw new \RuntimeException('Cannot reply to a deleted comment.');
        }
        return $this->insert($entityType, $entityId, $userId, $body, $parentId);
    }

    /**
     * Edit a comment body. Only the original author may edit.
     *
     * @return bool True if the comment was found, belongs to the user, and was updated.
     * @throws \InvalidArgumentException if body is empty.
     */
    public function edit(int $commentId, string $userId, string $body): bool
    {
        $userId = trim($userId);
        $body   = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE comments
             SET body = :body, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid AND deleted_at IS NULL'
        );
        $stmt->execute([':body' => $body, ':id' => $commentId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete a comment (body replaced with tombstone).
     *
     * Pass null as userId to force-delete regardless of author (admin use).
     *
     * @return bool True if the comment was found and soft-deleted.
     */
    public function delete(int $commentId, ?string $userId = null): bool
    {
        $params = [':body' => self::DELETED_BODY, ':id' => $commentId];
        if ($userId !== null) {
            $stmt = $this->db()->prepare(
                'UPDATE comments
                 SET body = :body, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :uid AND deleted_at IS NULL'
            );
            $params[':uid'] = trim($userId);
        } else {
            $stmt = $this->db()->prepare(
                'UPDATE comments
                 SET body = :body, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND deleted_at IS NULL'
            );
        }
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single comment by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $commentId): ?array
    {
        return $this->getRow($commentId);
    }

    /**
     * Get all comments for an entity, ordered by id ASC (includes tombstones).
     *
     * @return list<array<string,mixed>>
     */
    public function thread(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, parent_id, body, deleted_at, created_at, updated_at
             FROM comments
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY id ASC'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get top-level comments only (parent_id IS NULL).
     *
     * @return list<array<string,mixed>>
     */
    public function topLevel(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, parent_id, body, deleted_at, created_at, updated_at
             FROM comments
             WHERE entity_type = :type AND entity_id = :eid AND parent_id IS NULL
             ORDER BY id ASC'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all replies to a specific comment.
     *
     * @return list<array<string,mixed>>
     */
    public function replies(int $parentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, parent_id, body, deleted_at, created_at, updated_at
             FROM comments WHERE parent_id = :pid ORDER BY id ASC'
        );
        $stmt->execute([':pid' => $parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count non-deleted comments for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM comments
             WHERE entity_type = :type AND entity_id = :eid AND deleted_at IS NULL'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function insert(
        string $entityType,
        string $entityId,
        string $userId,
        string $body,
        ?int $parentId
    ): int {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $userId     = trim($userId);
        $body       = trim($body);

        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO comments (entity_type, entity_id, user_id, parent_id, body)
             VALUES (:type, :eid, :uid, :pid, :body)'
        );
        $stmt->execute([
            ':type' => $entityType,
            ':eid'  => $entityId,
            ':uid'  => $userId,
            ':pid'  => $parentId,
            ':body' => $body,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getRow(int $commentId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, parent_id, body, deleted_at, created_at, updated_at
             FROM comments WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $commentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
