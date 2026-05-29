<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Track user read progress through content (articles, books, courses, etc.).
 *
 * Progress is stored as an integer `position` (e.g. chapter number, page,
 * or a percentage × 100 for decimal precision) plus an optional `total`
 * for calculating completion percentage.
 *
 * ## Usage
 *
 * ```php
 * $rp = new ReadProgress($pdo);
 *
 * // Record progress (upsert)
 * $rp->save('user-1', 'article', '42', position: 75, total: 100);
 *
 * // Retrieve
 * $progress = $rp->get('user-1', 'article', '42');
 * // ['position' => 75, 'total' => 100, 'percent' => 75.0, 'completed' => false, ...]
 *
 * // Mark fully completed
 * $rp->markCompleted('user-1', 'article', '42');
 *
 * // List in-progress items for a user
 * $rp->listInProgress('user-1');
 * $rp->listCompleted('user-1', entityType: 'article');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE read_progress (
 *     user_id     VARCHAR(255) NOT NULL,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     position    INTEGER      NOT NULL DEFAULT 0,
 *     total       INTEGER      NOT NULL DEFAULT 0,
 *     completed   TINYINT(1)   NOT NULL DEFAULT 0,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (user_id, entity_type, entity_id)
 * );
 * ```
 */
final class ReadProgress
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save (upsert) user progress for an entity.
     *
     * @param int $position Current position (e.g. page, paragraph index, or percent×100).
     * @param int $total    Total units; 0 means unknown.
     * @throws \InvalidArgumentException if entity_type/entity_id empty or position negative.
     */
    public function save(
        string $userId,
        string $entityType,
        string $entityId,
        int $position,
        int $total = 0
    ): void {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        if ($position < 0) {
            throw new \InvalidArgumentException("Position must be >= 0, got {$position}.");
        }

        $completed = ($total > 0 && $position >= $total) ? 1 : 0;
        $db        = $this->db();
        $driver    = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO read_progress
                    (user_id, entity_type, entity_id, position, total, completed, updated_at)
                 VALUES (:u, :t, :e, :pos, :total, :completed, CURRENT_TIMESTAMP)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO read_progress
                    (user_id, entity_type, entity_id, position, total, completed, updated_at)
                 VALUES (:u, :t, :e, :pos, :total, :completed, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    position = VALUES(position),
                    total = VALUES(total),
                    completed = VALUES(completed),
                    updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            ':u'         => $userId,
            ':t'         => $entityType,
            ':e'         => $entityId,
            ':pos'       => $position,
            ':total'     => $total,
            ':completed' => $completed,
        ]);
    }

    /**
     * Mark an entity as fully completed.
     *
     * If no record exists yet, creates one with position = total = 1.
     *
     * @return bool True if a row was inserted or updated.
     */
    public function markCompleted(string $userId, string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $existing = $this->get($userId, $entityType, $entityId);

        $position = $existing !== null ? (int)$existing['total'] : 1;
        $total    = $position > 0 ? $position : 1;

        $this->save($userId, $entityType, $entityId, $position, $total);

        // Force completed = 1 in case total was 0
        $stmt = $this->db()->prepare(
            'UPDATE read_progress SET completed = 1, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :u AND entity_type = :t AND entity_id = :e'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a user's progress record for an entity.
     *
     * Returns null if no progress has been saved.
     * The returned array includes a computed `percent` float (0.0–100.0).
     *
     * @return array{user_id: string, entity_type: string, entity_id: string, position: int, total: int, completed: bool, percent: float, updated_at: string}|null
     */
    public function get(string $userId, string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT * FROM read_progress
             WHERE user_id = :u AND entity_type = :t AND entity_id = :e
             LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->decorate($row) : null;
    }

    /**
     * Delete a user's progress record for an entity.
     *
     * @return bool True if a row was deleted.
     */
    public function delete(string $userId, string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM read_progress WHERE user_id = :u AND entity_type = :t AND entity_id = :e'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List in-progress (not completed) items for a user.
     *
     * @param  string|null $entityType Optional filter.
     * @return list<array<string, mixed>>
     */
    public function listInProgress(string $userId, ?string $entityType = null): array
    {
        return $this->fetchList($userId, $entityType, completed: false);
    }

    /**
     * List completed items for a user.
     *
     * @param  string|null $entityType Optional filter.
     * @return list<array<string, mixed>>
     */
    public function listCompleted(string $userId, ?string $entityType = null): array
    {
        return $this->fetchList($userId, $entityType, completed: true);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $entityType, string $entityId): array
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

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $position  = (int)$row['position'];
        $total     = (int)$row['total'];
        $percent   = ($total > 0) ? round($position / $total * 100, 1) : 0.0;
        return array_merge($row, [
            'position'  => $position,
            'total'     => $total,
            'completed' => (bool)(int)$row['completed'],
            'percent'   => $percent,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $userId, ?string $entityType, bool $completed): array
    {
        $completedInt = $completed ? 1 : 0;
        if ($entityType !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM read_progress
                 WHERE user_id = :u AND entity_type = :t AND completed = :c
                 ORDER BY updated_at DESC'
            );
            $stmt->execute([':u' => $userId, ':t' => $entityType, ':c' => $completedInt]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM read_progress
                 WHERE user_id = :u AND completed = :c
                 ORDER BY updated_at DESC'
            );
            $stmt->execute([':u' => $userId, ':c' => $completedInt]);
        }
        return array_map(fn (array $r): array => $this->decorate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
