<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EntityComment — flat comment list attached to any entity.
 *
 * Stores user comments on arbitrary entities (posts, tasks, orders, …).
 * Comments are flat (no threading — use CommentThread for threaded replies).
 * Supports soft-delete and edit tracking.
 *
 * ## Usage
 *
 * ```php
 * $ec = new EntityComment($pdo);
 *
 * // Add
 * $id = $ec->add('article', '10', 'user-42', 'Great post!');
 *
 * // Edit / delete
 * $ec->edit($id, 'user-42', 'Great post, thanks!');
 * $ec->delete($id, 'user-42');
 *
 * // Query
 * $comments = $ec->forEntity('article', '10');
 * $count    = $ec->countForEntity('article', '10');
 * $mine     = $ec->byUser('user-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE entity_comments (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     author_id    VARCHAR(255) NOT NULL,
 *     body         TEXT         NOT NULL,
 *     edited_at    DATETIME     NULL,
 *     deleted_at   DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class EntityComment
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a comment.
     *
     * @return int Row ID of the new comment.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function add(string $entityType, string $entityId, string $authorId, string $body): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $authorId = trim($authorId);
        $body     = trim($body);
        if ($authorId === '') {
            throw new \InvalidArgumentException('authorId must not be empty.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO entity_comments (entity_type, entity_id, author_id, body)
             VALUES (:type, :eid, :author, :body)'
        );
        $stmt->execute([
            ':type'   => $entityType,
            ':eid'    => $entityId,
            ':author' => $authorId,
            ':body'   => $body,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single comment by ID (including soft-deleted).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM entity_comments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Edit a comment body (only the author can edit; cannot edit deleted comments).
     *
     * @return bool True if found, author matches, and not deleted.
     * @throws \InvalidArgumentException on empty body.
     */
    public function edit(int $id, string $authorId, string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE entity_comments
             SET body = :body, edited_at = :now
             WHERE id = :id AND author_id = :author AND deleted_at IS NULL'
        );
        $stmt->execute([':body' => $body, ':now' => $now, ':id' => $id, ':author' => trim($authorId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete a comment (only the author can delete).
     *
     * @return bool True if found, author matches, and not already deleted.
     */
    public function delete(int $id, string $authorId): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE entity_comments
             SET deleted_at = :now
             WHERE id = :id AND author_id = :author AND deleted_at IS NULL'
        );
        $stmt->execute([':now' => $now, ':id' => $id, ':author' => trim($authorId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete a comment permanently (admin use).
     *
     * @return bool True if found and deleted.
     */
    public function purge(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM entity_comments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List non-deleted comments for an entity (oldest first = natural conversation order).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId, int $limit = 100, int $offset = 0): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT * FROM entity_comments
             WHERE entity_type = :type AND entity_id = :eid AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':type', $entityType);
        $stmt->bindValue(':eid', $entityId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count non-deleted comments for an entity.
     */
    public function countForEntity(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM entity_comments
             WHERE entity_type = :type AND entity_id = :eid AND deleted_at IS NULL'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List non-deleted comments by a specific author (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function byUser(string $authorId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM entity_comments
             WHERE author_id = :author AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':author', trim($authorId));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
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
