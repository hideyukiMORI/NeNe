<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * KnowledgeBase — help articles with categories, lifecycle, and view tracking.
 *
 * Manages a simple help center / FAQ article store. Articles have a title,
 * body, optional category, and a status lifecycle (draft → published →
 * archived). Published articles can be listed by category or searched by
 * title/body. View counts are tracked per article.
 *
 * ## Usage
 *
 * ```php
 * $kb = new KnowledgeBase($pdo);
 *
 * // Create and publish
 * $id = $kb->create('How to reset password', $body, 'account');
 * $kb->publish($id);
 *
 * // Read
 * $kb->recordView($id);
 * $article = $kb->find($id);
 *
 * // List
 * $articles = $kb->published('account');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE kb_articles (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     title        VARCHAR(255) NOT NULL,
 *     body         TEXT         NOT NULL DEFAULT '',
 *     category     VARCHAR(100) NOT NULL DEFAULT '',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'draft',
 *     author_id    VARCHAR(255) NOT NULL DEFAULT '',
 *     views        INTEGER      NOT NULL DEFAULT 0,
 *     published_at DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class KnowledgeBase
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new article in draft status.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty title.
     */
    public function create(string $title, string $body = '', string $category = '', string $authorId = ''): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'INSERT INTO kb_articles (title, body, category, author_id)
             VALUES (:title, :body, :cat, :author)'
        );
        $stmt->execute([
            ':title'  => $title,
            ':body'   => $body,
            ':cat'    => trim($category),
            ':author' => trim($authorId),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Update the title and/or body of an article.
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty title.
     */
    public function update(int $id, string $title, string $body): bool
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE kb_articles SET title = :title, body = :body, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':title' => $title, ':body' => $body, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Publish a draft article.
     *
     * @return bool True if found and transitioned.
     */
    public function publish(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE kb_articles SET status = :pub, published_at = :now, updated_at = :now
             WHERE id = :id AND status = :draft'
        );
        $stmt->execute([
            ':pub'   => self::STATUS_PUBLISHED,
            ':now'   => $now,
            ':id'    => $id,
            ':draft' => self::STATUS_DRAFT,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Archive a published article.
     *
     * @return bool True if found and transitioned.
     */
    public function archive(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE kb_articles SET status = :arch, updated_at = :now
             WHERE id = :id AND status = :pub'
        );
        $stmt->execute([
            ':arch' => self::STATUS_ARCHIVED,
            ':now'  => $now,
            ':id'   => $id,
            ':pub'  => self::STATUS_PUBLISHED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Restore an archived article back to draft.
     *
     * @return bool True if found and transitioned.
     */
    public function restore(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE kb_articles SET status = :draft, updated_at = :now
             WHERE id = :id AND status = :arch'
        );
        $stmt->execute([
            ':draft' => self::STATUS_DRAFT,
            ':now'   => $now,
            ':id'    => $id,
            ':arch'  => self::STATUS_ARCHIVED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find an article by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM kb_articles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List published articles, optionally filtered by category.
     *
     * @return list<array<string,mixed>>
     */
    public function published(?string $category = null): array
    {
        if ($category !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, title, body, category, status, author_id, views, published_at, created_at, updated_at
                 FROM kb_articles
                 WHERE status = :pub AND category = :cat
                 ORDER BY published_at DESC, id DESC'
            );
            $stmt->execute([':pub' => self::STATUS_PUBLISHED, ':cat' => trim($category)]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, title, body, category, status, author_id, views, published_at, created_at, updated_at
                 FROM kb_articles
                 WHERE status = :pub
                 ORDER BY published_at DESC, id DESC'
            );
            $stmt->execute([':pub' => self::STATUS_PUBLISHED]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Search published articles by keyword (title or body contains).
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
        $stmt = $this->db()->prepare(
            'SELECT id, title, body, category, status, author_id, views, published_at, created_at, updated_at
             FROM kb_articles
             WHERE status = :pub AND (title LIKE :kw OR body LIKE :kw)
             ORDER BY views DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':pub', self::STATUS_PUBLISHED);
        $stmt->bindValue(':kw', $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Increment the view count for an article.
     *
     * @return bool True if found and updated.
     */
    public function recordView(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE kb_articles SET views = views + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an article permanently.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM kb_articles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
