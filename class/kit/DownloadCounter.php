<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Download counter — track file/asset download events.
 *
 * Records each download with an optional user ID and IP address.
 * Aggregates by entity for analytics; supports per-user download history.
 * Optionally enforces a per-user download limit for gated content.
 *
 * ## Usage
 *
 * ```php
 * $dc = new DownloadCounter($pdo);
 *
 * // Record a download
 * $dc->record('file', '42', userId: 'user-1', ipAddress: '1.2.3.4');
 *
 * // Count total downloads for a file
 * $dc->total('file', '42');
 *
 * // Count downloads by a specific user
 * $dc->countByUser('file', '42', 'user-1');
 *
 * // Check if user has reached a download limit
 * $dc->hasReachedLimit('file', '42', 'user-1', limit: 3);
 *
 * // Download history for a user
 * $dc->historyByUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE download_log (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     user_id      VARCHAR(255) NOT NULL DEFAULT '',
 *     ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
 *     downloaded_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DownloadCounter
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a download event.
     *
     * @param string $entityType e.g. 'file', 'report', 'asset'
     * @param string $entityId   Entity identifier
     * @param string $userId     Optional user performing the download
     * @param string $ipAddress  Optional IP for analytics/rate limiting
     * @return int New download log ID.
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     */
    public function record(
        string $entityType,
        string $entityId,
        string $userId = '',
        string $ipAddress = ''
    ): int {
        $entityType = $this->notEmpty($entityType, 'entity_type');
        $entityId   = $this->notEmpty($entityId, 'entity_id');

        $stmt = $this->db()->prepare(
            'INSERT INTO download_log (entity_type, entity_id, user_id, ip_address)
             VALUES (:type, :entity, :user, :ip)'
        );
        $stmt->execute([
            ':type'   => $entityType,
            ':entity' => $entityId,
            ':user'   => trim($userId),
            ':ip'     => trim($ipAddress),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Count total downloads for an entity.
     */
    public function total(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM download_log WHERE entity_type = :type AND entity_id = :entity'
        );
        $stmt->execute([':type' => $entityType, ':entity' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count how many times a specific user has downloaded an entity.
     */
    public function countByUser(string $entityType, string $entityId, string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM download_log
             WHERE entity_type = :type AND entity_id = :entity AND user_id = :user'
        );
        $stmt->execute([':type' => $entityType, ':entity' => $entityId, ':user' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a user has reached or exceeded a download limit for an entity.
     *
     * @param int $limit Maximum number of allowed downloads.
     */
    public function hasReachedLimit(string $entityType, string $entityId, string $userId, int $limit): bool
    {
        return $this->countByUser($entityType, $entityId, $userId) >= $limit;
    }

    /**
     * Return download history for a user (newest first).
     *
     * @param  int         $limit Max rows (1–100).
     * @param  string|null $entityType Optional filter.
     * @return list<array<string, mixed>>
     */
    public function historyByUser(string $userId, int $limit = 20, ?string $entityType = null): array
    {
        $limit = max(1, min(100, $limit));

        if ($entityType !== null) {
            $stmt = $this->db()->prepare(
                "SELECT * FROM download_log
                 WHERE user_id = :user AND entity_type = :type
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([':user' => $userId, ':type' => $entityType]);
        } else {
            $stmt = $this->db()->prepare(
                "SELECT * FROM download_log
                 WHERE user_id = :user
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([':user' => $userId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the top N most downloaded entities of a given type.
     *
     * @param  int $limit Max rows (1–50).
     * @return list<array{entity_id: string, downloads: int}>
     */
    public function topEntities(string $entityType, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt  = $this->db()->prepare(
            "SELECT entity_id, COUNT(*) AS downloads
             FROM download_log
             WHERE entity_type = :type
             GROUP BY entity_id
             ORDER BY downloads DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':type' => $entityType]);
        return array_map(
            static fn (array $r): array => ['entity_id' => $r['entity_id'], 'downloads' => (int)$r['downloads']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function notEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }
        return $value;
    }
}
