<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Content draft lifecycle: draft → published → archived.
 *
 * Content always starts as a draft. Only drafts can be published;
 * only published content can be archived. Reverse transitions are not allowed.
 *
 * Access control:
 * - List returns published content only.
 * - find() returns draft/archived content only to the author; others get null (hidden as 404).
 * - update() and publish() require draft status and author ownership.
 * - archive() requires published status and author ownership.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE contents (
 *     id           INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     author_id    VARCHAR(255) NOT NULL,
 *     title        VARCHAR(255) NOT NULL,
 *     body         TEXT         NOT NULL DEFAULT '',
 *     status       VARCHAR(16)  NOT NULL DEFAULT 'draft',
 *     published_at DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_contents_published ON contents (status, published_at);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $content = new ContentDraft();
 *
 * // Create
 * $id = $content->create('user:1', 'My Post', 'Draft body...');
 *
 * // Edit (draft only)
 * $content->update($id, 'user:1', 'My Post', 'Updated body...');
 *
 * // Publish
 * $content->publish($id, 'user:1');
 *
 * // Archive
 * $content->archive($id, 'user:1');
 *
 * // Find (non-author sees null for draft/archived)
 * $item = $content->find($id, viewerId: 'user:2');
 *
 * // List published (newest first)
 * $items = $content->listPublished();
 * ```
 */
final class ContentDraft
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    private const TABLE = 'contents';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Create a new draft.
     *
     * @param  string|int $authorId Application-level author identifier.
     * @param  string     $title    Content title.
     * @param  string     $body     Content body.
     * @return int        New content row ID.
     */
    public function create(string|int $authorId, string $title, string $body = ''): int
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (author_id, title, body, status, created_at, updated_at)' .
            ' VALUES (:a, :ti, :b, :s, :c, :u)'
        );
        $stmt->bindValue(':a', (string)$authorId, PDO::PARAM_STR);
        $stmt->bindValue(':ti', $title, PDO::PARAM_STR);
        $stmt->bindValue(':b', $body, PDO::PARAM_STR);
        $stmt->bindValue(':s', self::STATUS_DRAFT, PDO::PARAM_STR);
        $stmt->bindValue(':c', $now, PDO::PARAM_STR);
        $stmt->bindValue(':u', $now, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Update a draft. Returns false if not found, not a draft, or wrong author.
     *
     * @param int         $contentId  Row ID.
     * @param string|int  $authorId   Must match the content's author.
     * @param string      $title      New title.
     * @param string      $body       New body.
     */
    public function update(int $contentId, string|int $authorId, string $title, string $body = ''): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET title = :ti, body = :b, updated_at = :u' .
            ' WHERE id = :id AND author_id = :a AND status = :s'
        );
        $stmt->bindValue(':ti', $title, PDO::PARAM_STR);
        $stmt->bindValue(':b', $body, PDO::PARAM_STR);
        $stmt->bindValue(':u', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
        $stmt->bindValue(':a', (string)$authorId, PDO::PARAM_STR);
        $stmt->bindValue(':s', self::STATUS_DRAFT, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Publish a draft. Returns false if not a draft or wrong author.
     *
     * @param int         $contentId Row ID.
     * @param string|int  $authorId  Must match the content's author.
     */
    public function publish(int $contentId, string|int $authorId): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET status = :ns, published_at = :p, updated_at = :u' .
            ' WHERE id = :id AND author_id = :a AND status = :os'
        );
        $stmt->bindValue(':ns', self::STATUS_PUBLISHED, PDO::PARAM_STR);
        $stmt->bindValue(':p', $now, PDO::PARAM_STR);
        $stmt->bindValue(':u', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
        $stmt->bindValue(':a', (string)$authorId, PDO::PARAM_STR);
        $stmt->bindValue(':os', self::STATUS_DRAFT, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Archive a published item. Returns false if not published or wrong author.
     *
     * @param int         $contentId Row ID.
     * @param string|int  $authorId  Must match the content's author.
     */
    public function archive(int $contentId, string|int $authorId): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET status = :ns, updated_at = :u' .
            ' WHERE id = :id AND author_id = :a AND status = :os'
        );
        $stmt->bindValue(':ns', self::STATUS_ARCHIVED, PDO::PARAM_STR);
        $stmt->bindValue(':u', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
        $stmt->bindValue(':a', (string)$authorId, PDO::PARAM_STR);
        $stmt->bindValue(':os', self::STATUS_PUBLISHED, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Find a content item by ID.
     *
     * Draft and archived items are visible only to their author.
     * Non-author viewers receive null for non-published content (hidden as 404).
     *
     * @param  int               $contentId Row ID.
     * @param  string|int|null   $viewerId  Current viewer. Null = anonymous.
     * @return array{id:int,author_id:string,title:string,body:string,status:string,published_at:string|null,created_at:string,updated_at:string}|null
     */
    public function find(int $contentId, string|int|null $viewerId = null): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, author_id, title, body, status, published_at, created_at, updated_at' .
            ' FROM ' . $this->table . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Non-published content is hidden from non-authors
        if ($row['status'] !== self::STATUS_PUBLISHED) {
            if ($viewerId === null || (string)$viewerId !== (string)$row['author_id']) {
                return null;
            }
        }

        return [
            'id'           => (int)$row['id'],
            'author_id'    => (string)$row['author_id'],
            'title'        => (string)$row['title'],
            'body'         => (string)$row['body'],
            'status'       => (string)$row['status'],
            'published_at' => $row['published_at'] !== null ? (string)$row['published_at'] : null,
            'created_at'   => (string)$row['created_at'],
            'updated_at'   => (string)$row['updated_at'],
        ];
    }

    /**
     * List published content, newest first.
     *
     * Sorted by (published_at DESC, id DESC) for stable order when multiple
     * items are published within the same second.
     *
     * @return list<array{id:int,author_id:string,title:string,body:string,published_at:string,created_at:string}>
     */
    public function listPublished(): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, author_id, title, body, published_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE status = :s ORDER BY published_at DESC, id DESC'
        );
        $stmt->bindValue(':s', self::STATUS_PUBLISHED, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'           => (int)$r['id'],
            'author_id'    => (string)$r['author_id'],
            'title'        => (string)$r['title'],
            'body'         => (string)$r['body'],
            'published_at' => (string)$r['published_at'],
            'created_at'   => (string)$r['created_at'],
        ], $rows);
    }
}
