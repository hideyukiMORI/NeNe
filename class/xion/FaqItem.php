<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * FaqItem — FAQ articles with categories, ordering, and helpfulness voting.
 *
 * Stores structured FAQ items with category grouping, explicit display
 * ordering, publish/unpublish control, and per-item helpfulness tracking.
 * Supports keyword search across question and answer text.
 *
 * ## Usage
 *
 * ```php
 * $faq = new FaqItem($pdo);
 *
 * $id = $faq->add('billing', 'How do I cancel?', 'Go to Settings > Account.', 1);
 * $faq->publish($id);
 *
 * $items  = $faq->forCategory('billing');
 * $found  = $faq->search('cancel');
 * $cats   = $faq->allCategories();
 *
 * $faq->voteHelpful($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE faq_items (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     category    VARCHAR(100) NOT NULL DEFAULT 'general',
 *     question    TEXT         NOT NULL,
 *     answer      TEXT         NOT NULL,
 *     position    INTEGER      NOT NULL DEFAULT 0,
 *     published   TINYINT(1)   NOT NULL DEFAULT 1,
 *     helpful     INTEGER      NOT NULL DEFAULT 0,
 *     not_helpful INTEGER      NOT NULL DEFAULT 0,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class FaqItem
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a FAQ item. New items are published by default.
     *
     * @return int Row ID of the new item.
     * @throws \InvalidArgumentException on empty question or answer.
     */
    public function add(string $category, string $question, string $answer, int $position = 0): int
    {
        $category = trim($category) === '' ? 'general' : trim($category);
        $question = trim($question);
        $answer   = trim($answer);
        if ($question === '') {
            throw new \InvalidArgumentException('question must not be empty.');
        }
        if ($answer === '') {
            throw new \InvalidArgumentException('answer must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO faq_items (category, question, answer, position, created_at, updated_at)
             VALUES (:cat, :q, :a, :pos, :now, :now)'
        );
        $stmt->execute([
            ':cat' => $category,
            ':q'   => $question,
            ':a'   => $answer,
            ':pos' => $position,
            ':now' => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Update question and answer text.
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty question or answer.
     */
    public function update(int $id, string $question, string $answer): bool
    {
        $question = trim($question);
        $answer   = trim($answer);
        if ($question === '') {
            throw new \InvalidArgumentException('question must not be empty.');
        }
        if ($answer === '') {
            throw new \InvalidArgumentException('answer must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE faq_items SET question = :q, answer = :a, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':q' => $question, ':a' => $answer, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Publish a FAQ item (make visible).
     *
     * @return bool True if found and updated.
     */
    public function publish(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE faq_items SET published = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Unpublish a FAQ item (hide from public).
     *
     * @return bool True if found and updated.
     */
    public function unpublish(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE faq_items SET published = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a FAQ item.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM faq_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a FAQ item by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, category, question, answer, position, published,
                    helpful, not_helpful, created_at, updated_at
             FROM faq_items WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * List FAQ items for a category, ordered by position then id.
     *
     * @return list<array<string,mixed>>
     */
    public function forCategory(string $category, bool $publishedOnly = true): array
    {
        $category = trim($category) === '' ? 'general' : trim($category);
        $sql      = 'SELECT id, category, question, answer, position, published,
                            helpful, not_helpful, created_at, updated_at
                     FROM faq_items WHERE category = :cat';
        if ($publishedOnly) {
            $sql .= ' AND published = 1';
        }
        $sql .= ' ORDER BY position ASC, id ASC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':cat' => $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return distinct category names ordered alphabetically.
     *
     * @return list<string>
     */
    public function allCategories(): array
    {
        $stmt = $this->db()->query(
            'SELECT DISTINCT category FROM faq_items ORDER BY category ASC'
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Search FAQ items by keyword (question and answer), published only.
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $keyword): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($keyword)) . '%';
        $stmt = $this->db()->prepare(
            'SELECT id, category, question, answer, position, published,
                    helpful, not_helpful, created_at, updated_at
             FROM faq_items
             WHERE published = 1 AND (question LIKE :kw OR answer LIKE :kw)
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':kw' => $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Change display position of a FAQ item.
     *
     * @return bool True if found and updated.
     */
    public function reorder(int $id, int $position): bool
    {
        $stmt = $this->db()->prepare('UPDATE faq_items SET position = :pos WHERE id = :id');
        $stmt->execute([':pos' => $position, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Increment the helpful vote counter.
     *
     * @return bool True if found and updated.
     */
    public function voteHelpful(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE faq_items SET helpful = helpful + 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Increment the not-helpful vote counter.
     *
     * @return bool True if found and updated.
     */
    public function voteNotHelpful(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE faq_items SET not_helpful = not_helpful + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
