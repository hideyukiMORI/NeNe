<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SearchSuggestion — type-ahead suggestion management with frequency weighting.
 *
 * Records user search queries and surfaces normalised suggestions ordered by
 * frequency. Supports manual weight boosts for editorial pinning. Suggestions
 * are normalised to lowercase with collapsed whitespace before storage.
 *
 * ## Usage
 *
 * ```php
 * $ss = new SearchSuggestion($pdo);
 *
 * // Record user queries
 * $ss->record('PHP framework');
 * $ss->record('PHP framework');
 * $ss->record('php tutorial');
 *
 * // Type-ahead
 * $results = $ss->suggest('php', 5);
 * // → [['term' => 'php framework', 'frequency' => 2, 'weight' => 0], ...]
 *
 * // Editorial boost
 * $ss->boost('php tutorial', 10);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE search_suggestions (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     term       VARCHAR(500) NOT NULL UNIQUE,
 *     frequency  INTEGER      NOT NULL DEFAULT 0,
 *     weight     INTEGER      NOT NULL DEFAULT 0,
 *     last_seen  DATETIME     NULL
 * );
 * ```
 */
final class SearchSuggestion
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a search query, incrementing frequency.
     *
     * The term is normalised (lowercase, collapsed whitespace) before storage.
     * If the term already exists the frequency is incremented; otherwise a new
     * row is inserted.
     *
     * @throws \InvalidArgumentException on empty term after normalisation.
     */
    public function record(string $term): void
    {
        $term = $this->normalise($term);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO search_suggestions (term, frequency, last_seen)
                    VALUES (:term, 1, :now)
                    ON CONFLICT (term) DO UPDATE SET frequency = frequency + 1, last_seen = :now2';
            $this->db()->prepare($sql)->execute([':term' => $term, ':now' => $now, ':now2' => $now]);
        } else {
            $sql = 'INSERT INTO search_suggestions (term, frequency, last_seen)
                    VALUES (:term, 1, :now)
                    ON DUPLICATE KEY UPDATE frequency = frequency + 1, last_seen = :now2';
            $this->db()->prepare($sql)->execute([':term' => $term, ':now' => $now, ':now2' => $now]);
        }
    }

    /**
     * Return suggestions matching a prefix (case-insensitive), ordered by
     * (weight + frequency) descending.
     *
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException on empty prefix after normalisation.
     */
    public function suggest(string $prefix, int $limit = 10): array
    {
        $prefix = $this->normalise($prefix);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            'SELECT term, frequency, weight
             FROM search_suggestions
             WHERE term LIKE :prefix
             ORDER BY (weight + frequency) DESC, term ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':prefix', $prefix . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Set a manual weight boost for a term.
     *
     * @return bool True if the term exists and was updated.
     * @throws \InvalidArgumentException on empty term or negative weight.
     */
    public function boost(string $term, int $weight): bool
    {
        $term = $this->normalise($term);
        if ($weight < 0) {
            throw new \InvalidArgumentException('weight must be >= 0.');
        }
        $stmt = $this->db()->prepare('UPDATE search_suggestions SET weight = :w WHERE term = :term');
        $stmt->execute([':w' => $weight, ':term' => $term]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a single suggestion term.
     *
     * @return bool True if found and deleted.
     */
    public function remove(string $term): bool
    {
        $term = $this->normalise($term);
        $stmt = $this->db()->prepare('DELETE FROM search_suggestions WHERE term = :term');
        $stmt->execute([':term' => $term]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete suggestions not seen since $cutoff (for TTL cleanup).
     *
     * @return int Number of rows deleted.
     */
    public function purge(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM search_suggestions WHERE last_seen < :cutoff OR last_seen IS NULL'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Return the stored frequency for a term (0 if not found).
     */
    public function frequency(string $term): int
    {
        $term = $this->normalise($term);
        $stmt = $this->db()->prepare('SELECT frequency FROM search_suggestions WHERE term = :term');
        $stmt->execute([':term' => $term]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)$row['frequency'] : 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function normalise(string $term): string
    {
        $term = mb_strtolower(trim($term));
        $term = (string)preg_replace('/\s+/', ' ', $term);
        if ($term === '') {
            throw new \InvalidArgumentException('term must not be empty.');
        }
        return $term;
    }
}
