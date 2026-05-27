<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Threaded comment system with nesting depth limit and soft delete.
 *
 * Comments belong to an entity identified by (entity_type, entity_id).
 * Replies are linked via parent_id. Maximum nesting depth is enforced
 * to prevent runaway tree growth.
 *
 * Deleted comments have their body replaced with a placeholder and
 * their author_id set to null, but the row is retained so reply
 * threading is preserved.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE comments (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(64)  NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     parent_id   INTEGER      DEFAULT NULL,
 *     author_id   VARCHAR(255),
 *     body        TEXT         NOT NULL,
 *     depth       INTEGER      NOT NULL DEFAULT 0,
 *     deleted_at  DATETIME     DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_comments_entity ON comments (entity_type, entity_id, parent_id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $ct = new CommentThread($pdo);
 *
 * // Top-level comment
 * $id1 = $ct->post('article', '42', 'user:1', 'Great article!');
 *
 * // Reply (depth = parent.depth + 1)
 * $id2 = $ct->reply($id1, 'user:2', 'Thanks!');
 *
 * // List (non-deleted, ordered by id ASC)
 * $ct->list('article', '42');
 *
 * // Soft delete (body replaced, author_id nulled)
 * $ct->softDelete($id1, 'user:1');
 * ```
 */
final class CommentThread
{
    private const TABLE     = 'comments';
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table    = self::TABLE,
        private readonly int $maxDepth    = self::MAX_DEPTH,
    ) {
    }

    /**
     * Post a top-level comment on an entity.
     *
     * @param  string $entityType Entity type (e.g. 'article', 'product').
     * @param  string $entityId   Entity ID.
     * @param  string $authorId   Author identifier.
     * @param  string $body       Comment body.
     * @return int New comment ID.
     * @throws \InvalidArgumentException if $body is empty.
     */
    public function post(string $entityType, string $entityId, string $authorId, string $body): int
    {
        $this->assertBody($body);

        $db        = $this->db ?? PdoConnection::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (entity_type, entity_id, author_id, body, depth, created_at)' .
            ' VALUES (:etype, :eid, :author, :body, 0, :t)'
        );
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->bindValue(':author', $authorId, PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':t', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Reply to an existing comment.
     *
     * @param  int    $parentId Target comment ID to reply to.
     * @param  string $authorId Author identifier.
     * @param  string $body     Reply body.
     * @return int New comment ID.
     * @throws \InvalidArgumentException if $body is empty, parent not found, or max depth exceeded.
     */
    public function reply(int $parentId, string $authorId, string $body): int
    {
        $this->assertBody($body);

        $db     = $this->db ?? PdoConnection::getInstance();
        $parent = $this->findComment($parentId, $db);

        if ($parent === null) {
            throw new \InvalidArgumentException("Parent comment {$parentId} not found.");
        }

        $newDepth = (int)$parent['depth'] + 1;
        if ($newDepth > $this->maxDepth) {
            throw new \InvalidArgumentException(
                "Maximum comment depth ({$this->maxDepth}) reached."
            );
        }

        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (entity_type, entity_id, parent_id, author_id, body, depth, created_at)' .
            ' VALUES (:etype, :eid, :pid, :author, :body, :depth, :t)'
        );
        $stmt->bindValue(':etype', $parent['entity_type'], PDO::PARAM_STR);
        $stmt->bindValue(':eid', $parent['entity_id'], PDO::PARAM_STR);
        $stmt->bindValue(':pid', $parentId, PDO::PARAM_INT);
        $stmt->bindValue(':author', $authorId, PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':depth', $newDepth, PDO::PARAM_INT);
        $stmt->bindValue(':t', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Soft-delete a comment. Only the author can delete their own comment.
     *
     * The body is replaced with a placeholder and author_id is nulled.
     * The row is retained to preserve reply threading.
     *
     * Returns true if deleted, false if not found or wrong author.
     *
     * @param int    $commentId Comment to delete.
     * @param string $authorId  Must match the comment's author_id.
     */
    public function softDelete(int $commentId, string $authorId): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $deletedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET body = \'[deleted]\', author_id = NULL, deleted_at = :t' .
            ' WHERE id = :id AND author_id = :author AND deleted_at IS NULL'
        );
        $stmt->bindValue(':t', $deletedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $commentId, PDO::PARAM_INT);
        $stmt->bindValue(':author', $authorId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * List all comments for an entity, ordered by id ASC (includes soft-deleted).
     *
     * @param  string $entityType Entity type.
     * @param  string $entityId   Entity ID.
     * @return list<array{id:int,parent_id:int|null,author_id:string|null,body:string,depth:int,deleted_at:string|null,created_at:string}>
     */
    public function list(string $entityType, string $entityId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, parent_id, author_id, body, depth, deleted_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE entity_type = :etype AND entity_id = :eid' .
            ' ORDER BY id ASC'
        );
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'         => (int)$r['id'],
            'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'author_id'  => $r['author_id'] !== null ? (string)$r['author_id'] : null,
            'body'       => (string)$r['body'],
            'depth'      => (int)$r['depth'],
            'deleted_at' => $r['deleted_at'] !== null ? (string)$r['deleted_at'] : null,
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * @return array{entity_type:string,entity_id:string,depth:int}|null
     */
    private function findComment(int $id, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT entity_type, entity_id, depth FROM ' . $this->table .
            ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'entity_type' => (string)$row['entity_type'],
            'entity_id'   => (string)$row['entity_id'],
            'depth'       => (int)$row['depth'],
        ];
    }

    private function assertBody(string $body): void
    {
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Comment body must not be empty.');
        }
    }
}
