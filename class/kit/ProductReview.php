<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ProductReview — entity reviews with ratings and helpfulness voting.
 *
 * Supports 1–5 star ratings, optional review text, an approval workflow
 * (pending → approved | rejected), and per-item helpfulness voting.
 * One review per user per entity (UNIQUE constraint).
 *
 * ## Usage
 *
 * ```php
 * $r = new ProductReview($pdo);
 *
 * $id = $r->submit('product', '42', 'user-7', 5, 'Great product!');
 * $r->approve($id);
 *
 * $reviews = $r->forEntity('product', '42');         // approved only
 * $avg     = $r->averageRating('product', '42');     // 5.0
 * $breakdown = $r->ratingBreakdown('product', '42'); // [5 => 1, ...]
 *
 * $r->voteHelpful($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE reviews (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     rating      TINYINT(1)   NOT NULL,
 *     body        TEXT         NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     helpful     INTEGER      NOT NULL DEFAULT 0,
 *     not_helpful INTEGER      NOT NULL DEFAULT 0,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, user_id)
 * );
 * ```
 */
final class ProductReview
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a review (one per user per entity — throws on duplicate).
     *
     * @return int Row ID of the new review.
     * @throws \InvalidArgumentException on invalid rating or empty entity.
     */
    public function submit(
        string $entityType,
        string $entityId,
        string $userId,
        int $rating,
        ?string $body = null
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('rating must be between 1 and 5.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO reviews (entity_type, entity_id, user_id, rating, body)
             VALUES (:type, :eid, :uid, :rating, :body)'
        );
        $stmt->execute([
            ':type'   => $entityType,
            ':eid'    => $entityId,
            ':uid'    => $userId,
            ':rating' => $rating,
            ':body'   => $body,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a review by id.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, rating, body, status,
                    helpful, not_helpful, created_at
             FROM reviews WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Approve a review (pending → approved).
     *
     * @return bool True if found and updated.
     */
    public function approve(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE reviews SET status = 'approved' WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a review (pending → rejected).
     *
     * @return bool True if found and updated.
     */
    public function reject(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE reviews SET status = 'rejected' WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a review.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List reviews for an entity, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId, bool $approvedOnly = true): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $sql = 'SELECT id, entity_type, entity_id, user_id, rating, body, status,
                       helpful, not_helpful, created_at
                FROM reviews
                WHERE entity_type = :type AND entity_id = :eid';
        if ($approvedOnly) {
            $sql .= " AND status = 'approved'";
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all reviews submitted by a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function byUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, rating, body, status,
                    helpful, not_helpful, created_at
             FROM reviews WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return average rating (0.0 if no approved reviews).
     */
    public function averageRating(string $entityType, string $entityId): float
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            "SELECT AVG(rating) FROM reviews
             WHERE entity_type = :type AND entity_id = :eid AND status = 'approved'"
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $val = $stmt->fetchColumn();
        return $val === null || $val === false ? 0.0 : (float)$val;
    }

    /**
     * Return rating distribution for approved reviews.
     * Keys 1–5, values are counts (missing stars have count 0).
     *
     * @return array<int,int>
     */
    public function ratingBreakdown(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            "SELECT rating, COUNT(*) AS cnt
             FROM reviews
             WHERE entity_type = :type AND entity_id = :eid AND status = 'approved'
             GROUP BY rating"
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $result[(int)$row['rating']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Increment the helpful vote counter for a review.
     *
     * @return bool True if found and updated.
     */
    public function voteHelpful(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE reviews SET helpful = helpful + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Increment the not-helpful vote counter for a review.
     *
     * @return bool True if found and updated.
     */
    public function voteNotHelpful(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE reviews SET not_helpful = not_helpful + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ──────────────────────────────────────────────────────

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
