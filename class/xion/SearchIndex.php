<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SearchIndex — lightweight full-text search index for any entity.
 *
 * Stores a searchable text blob per entity, tokenised into lowercased words.
 * Supports prefix-match search and ranked results by term frequency.
 *
 * Not intended to replace Elasticsearch or MySQL FTS — suited for small
 * to medium datasets where a dedicated search server is not warranted.
 *
 * ## Usage
 *
 * ```php
 * $si = new SearchIndex($pdo);
 *
 * // Index a document
 * $si->index('post', '42', 'PHP arrays are powerful data structures');
 *
 * // Search
 * $results = $si->search('php array', 10);
 * // [['entity_type' => 'post', 'entity_id' => '42', 'score' => 2], ...]
 *
 * // Remove from index
 * $si->remove('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE search_index (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     token       VARCHAR(100) NOT NULL,
 *     frequency   INT          NOT NULL DEFAULT 1,
 *     indexed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, token)
 * );
 * ```
 */
final class SearchIndex
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Index (or re-index) an entity's searchable text.
     *
     * Existing tokens for the entity are replaced.
     *
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     */
    public function index(string $entityType, string $entityId, string $text): void
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $db                      = $this->db();
        $driver                  = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Remove existing tokens
        $db->prepare(
            'DELETE FROM search_index WHERE entity_type = :type AND entity_id = :eid'
        )->execute([':type' => $entityType, ':eid' => $entityId]);

        $tokens = $this->tokenise($text);
        if (empty($tokens)) {
            return;
        }

        // Count frequencies
        $freq = array_count_values($tokens);

        foreach ($freq as $token => $count) {
            if ($driver === 'sqlite') {
                $db->prepare(
                    'INSERT OR IGNORE INTO search_index (entity_type, entity_id, token, frequency)
                     VALUES (:type, :eid, :token, :freq)'
                )->execute([':type' => $entityType, ':eid' => $entityId, ':token' => $token, ':freq' => $count]);
            } else {
                $db->prepare(
                    'INSERT IGNORE INTO search_index (entity_type, entity_id, token, frequency)
                     VALUES (:type, :eid, :token, :freq)'
                )->execute([':type' => $entityType, ':eid' => $entityId, ':token' => $token, ':freq' => $count]);
            }
        }
    }

    /**
     * Search for entities matching all query terms (AND logic).
     *
     * Results are ranked by total term frequency (sum of matched token frequencies).
     *
     * @param  string      $query      Space-separated search terms.
     * @param  int         $limit      Max results to return.
     * @param  string|null $entityType Filter to a specific entity type.
     * @return list<array{entity_type: string, entity_id: string, score: int}>
     */
    public function search(string $query, int $limit = 20, ?string $entityType = null): array
    {
        $tokens = $this->tokenise($query);
        if (empty($tokens)) {
            return [];
        }

        $limit     = max(1, $limit);
        $tokenCount = count($tokens);
        $db        = $this->db();

        // Build IN clause
        $in     = implode(',', array_fill(0, $tokenCount, '?'));
        $params = $tokens;

        if ($entityType !== null) {
            $sql = "SELECT entity_type, entity_id, SUM(frequency) AS score
                    FROM search_index
                    WHERE token IN ({$in}) AND entity_type = ?
                    GROUP BY entity_type, entity_id
                    HAVING COUNT(DISTINCT token) = {$tokenCount}
                    ORDER BY score DESC
                    LIMIT {$limit}";
            $params[] = $entityType;
        } else {
            $sql = "SELECT entity_type, entity_id, SUM(frequency) AS score
                    FROM search_index
                    WHERE token IN ({$in})
                    GROUP BY entity_type, entity_id
                    HAVING COUNT(DISTINCT token) = {$tokenCount}
                    ORDER BY score DESC
                    LIMIT {$limit}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $r) => [
                'entity_type' => (string)$r['entity_type'],
                'entity_id'   => (string)$r['entity_id'],
                'score'       => (int)$r['score'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Remove an entity from the index.
     *
     * @return bool True if tokens were deleted.
     */
    public function remove(string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'DELETE FROM search_index WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count indexed entities.
     */
    public function count(?string $entityType = null): int
    {
        if ($entityType !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(DISTINCT entity_id) FROM search_index WHERE entity_type = :type'
            );
            $stmt->execute([':type' => $entityType]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(DISTINCT CAST(entity_type AS TEXT) || \'|\' || CAST(entity_id AS TEXT)) FROM search_index'
            );
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $text): array
    {
        $text = mb_strtolower($text);
        // Split on non-word characters, filter short tokens
        $raw = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($raw, static fn (string $t) => mb_strlen($t) >= 2));
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
}
