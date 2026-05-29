<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * CommentThread — threaded comments attached to any entity.
 *
 * Comments can be top-level or replies to another comment (one level of nesting).
 * Soft-delete preserves thread structure while hiding body content.
 *
 * ## Usage
 *
 * ```php
 * $ct = new CommentThread($pdo);
 *
 * // Add a top-level comment
 * $id = $ct->add('post', '42', 'user-1', 'Great article!');
 *
 * // Reply to a comment
 * $replyId = $ct->add('post', '42', 'user-2', 'Thanks!', $id);
 *
 * // List all comments (flat, ordered by id)
 * $ct->list('post', '42');
 *
 * // Soft-delete
 * $ct->delete($id, 'user-1');
 *
 * // Count
 * $ct->count('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE comments (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type   VARCHAR(100) NOT NULL,
 *     entity_id     VARCHAR(255) NOT NULL,
 *     parent_id     INTEGER      DEFAULT NULL,
 *     author_id     VARCHAR(255) NOT NULL,
 *     body          TEXT         NOT NULL DEFAULT '',
 *     deleted_at    DATETIME     DEFAULT NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class CommentThread
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a comment (or reply) to an entity.
     *
     * @param  int|null $parentId Parent comment ID for replies; null for top-level.
     * @return int The new comment ID.
     * @throws \InvalidArgumentException if entity_type, entity_id, or author_id is empty.
     * @throws \InvalidArgumentException if body is empty.
     */
    public function add(
        string $entityType,
        string $entityId,
        string $authorId,
        string $body,
        ?int $parentId = null
    ): int {
        [$entityType, $entityId] = $this->normaliseEntity($entityType, $entityId);
        $authorId                = trim($authorId);
        if ($authorId === '') {
            throw new \InvalidArgumentException('author_id must not be empty.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $db = $this->db();
        $db->prepare(
            'INSERT INTO comments (entity_type, entity_id, parent_id, author_id, body)
             VALUES (:type, :eid, :parent, :author, :body)'
        )->execute([
            ':type'   => $entityType,
            ':eid'    => $entityId,
            ':parent' => $parentId,
            ':author' => $authorId,
            ':body'   => $body,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Edit a comment body.
     *
     * @return bool True if the comment was found, not deleted, and updated.
     */
    public function edit(int $commentId, string $authorId, string $newBody): bool
    {
        $newBody = trim($newBody);
        if ($newBody === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'UPDATE comments
             SET body = :body, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND author_id = :author AND deleted_at IS NULL'
        );
        $stmt->execute([':body' => $newBody, ':id' => $commentId, ':author' => $authorId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete a comment (preserves replies structure, blanks body).
     *
     * @return bool True if the comment was found and soft-deleted.
     */
    public function delete(int $commentId, string $authorId): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE comments
             SET body = '', deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND author_id = :author AND deleted_at IS NULL"
        );
        $stmt->execute([':id' => $commentId, ':author' => $authorId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete a comment (admin use, also removes orphaned replies).
     *
     * @return bool True if the comment was found and deleted.
     */
    public function remove(int $commentId): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM comments WHERE parent_id = :id')->execute([':id' => $commentId]);
        $stmt = $db->prepare('DELETE FROM comments WHERE id = :id');
        $stmt->execute([':id' => $commentId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single comment by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $commentId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, parent_id, author_id, body,
                    deleted_at, created_at, updated_at
             FROM comments WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $commentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all comments for an entity, ordered by id ASC (includes soft-deleted).
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $entityType, string $entityId, bool $includeDeleted = true): array
    {
        [$entityType, $entityId] = $this->normaliseEntity($entityType, $entityId);

        if ($includeDeleted) {
            $stmt = $this->db()->prepare(
                'SELECT id, entity_type, entity_id, parent_id, author_id, body,
                        deleted_at, created_at, updated_at
                 FROM comments
                 WHERE entity_type = :type AND entity_id = :eid
                 ORDER BY id ASC'
            );
            $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, entity_type, entity_id, parent_id, author_id, body,
                        deleted_at, created_at, updated_at
                 FROM comments
                 WHERE entity_type = :type AND entity_id = :eid AND deleted_at IS NULL
                 ORDER BY id ASC'
            );
            $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count non-deleted comments for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->normaliseEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT COUNT(*) FROM comments
             WHERE entity_type = :type AND entity_id = :eid AND deleted_at IS NULL'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List replies to a given comment.
     *
     * @return list<array<string,mixed>>
     */
    public function replies(int $parentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, parent_id, author_id, body,
                    deleted_at, created_at, updated_at
             FROM comments WHERE parent_id = :pid ORDER BY id ASC'
        );
        $stmt->execute([':pid' => $parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normaliseEntity(string $entityType, string $entityId): array
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
