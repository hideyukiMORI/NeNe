<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Per-user search history with deduplication and auto-trimming.
 *
 * Each push upserts: if the query already exists for the user, its
 * `searched_at` is updated (moved to top). The history is trimmed to at
 * most $maxEntries per user after each push.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE search_history (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     query       VARCHAR(255) NOT NULL,
 *     searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, query)
 * );
 * CREATE INDEX idx_search_history_user ON search_history (user_id, searched_at DESC);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $sh = new SearchHistory($pdo);
 *
 * $sh->push('user:1', 'php arrays');
 * $sh->push('user:1', 'oop patterns');
 * $sh->push('user:1', 'php arrays');  // moves to top, no duplicate
 *
 * $sh->recent('user:1');   // ['php arrays', 'oop patterns']
 * $sh->clear('user:1');    // removes all
 * ```
 */
final class SearchHistory
{
    private const TABLE       = 'search_history';
    private const MAX_ENTRIES = 20;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table      = self::TABLE,
        private readonly int $maxEntries    = self::MAX_ENTRIES,
    ) {
    }

    /**
     * Push a query to the top of the user's search history.
     *
     * If the query already exists, its timestamp is updated (upsert).
     * After inserting, entries beyond $maxEntries are pruned (oldest first).
     *
     * @param string $userId Application-level user identifier.
     * @param string $query  Search query string.
     * @throws \InvalidArgumentException if $query is empty after trim.
     */
    public function push(string $userId, string $query): void
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Search query must not be empty.');
        }

        $db         = $this->db ?? PdoConnection::getInstance();
        $driver     = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $searchedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR REPLACE INTO ' . $this->table .
                   ' (user_id, query, searched_at) VALUES (:u, :q, :t)';
        } else {
            $sql = 'INSERT INTO ' . $this->table .
                   ' (user_id, query, searched_at) VALUES (:u, :q, :t)' .
                   ' ON DUPLICATE KEY UPDATE searched_at = VALUES(searched_at)';
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':q', $query, PDO::PARAM_STR);
        $stmt->bindValue(':t', $searchedAt, PDO::PARAM_STR);
        $stmt->execute();

        $this->trim($userId, $db);
    }

    /**
     * Get recent search queries for a user, newest first.
     *
     * @param  string $userId Application-level user identifier.
     * @param  int    $limit  Maximum entries to return (1–$maxEntries).
     * @return list<string>
     */
    public function recent(string $userId, int $limit = 10): array
    {
        $limit = max(1, min($this->maxEntries, $limit));

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT query FROM ' . $this->table .
            ' WHERE user_id = :u' .
            ' ORDER BY searched_at DESC, id DESC' .
            ' LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'query');
    }

    /**
     * Clear all search history for a user.
     *
     * Returns the number of entries removed.
     *
     * @param string $userId Application-level user identifier.
     */
    public function clear(string $userId): int
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table . ' WHERE user_id = :u'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * Trim entries beyond $maxEntries for a user, keeping the most recent.
     */
    private function trim(string $userId, PDO $db): void
    {
        // Count current entries
        $count = $db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE user_id = :u'
        );
        $count->bindValue(':u', $userId, PDO::PARAM_STR);
        $count->execute();

        $total = (int)$count->fetchColumn();
        if ($total <= $this->maxEntries) {
            return;
        }

        // Delete oldest (lowest searched_at, then lowest id) beyond limit
        $excess = $total - $this->maxEntries;
        $del    = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE user_id = :u' .
            ' AND id IN (' .
            '   SELECT id FROM ' . $this->table .
            '   WHERE user_id = :u2' .
            '   ORDER BY searched_at ASC, id ASC' .
            '   LIMIT :excess' .
            ' )'
        );
        $del->bindValue(':u', $userId, PDO::PARAM_STR);
        $del->bindValue(':u2', $userId, PDO::PARAM_STR);
        $del->bindValue(':excess', $excess, PDO::PARAM_INT);
        $del->execute();
    }
}
