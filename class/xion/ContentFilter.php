<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ContentFilter — DB-backed banned word/phrase list with masking.
 *
 * Manages a list of banned words or phrases (stored in the database) and
 * provides text screening and masking utilities. Words are matched
 * case-insensitively. Whole-word matching can be enabled to avoid false
 * positives on substrings.
 *
 * ## Usage
 *
 * ```php
 * $cf = new ContentFilter($pdo);
 *
 * // Manage list
 * $cf->addWord('badword');
 * $cf->removeWord('badword');
 *
 * // Screen text
 * if ($cf->contains('This has badword in it')) {
 *     // reject or mask
 * }
 * $clean = $cf->mask('This has badword in it');   // 'This has ******* in it'
 * $words = $cf->detect('This has badword in it'); // ['badword']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE banned_words (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     word       VARCHAR(255) NOT NULL,
 *     category   VARCHAR(100) NOT NULL DEFAULT '',
 *     added_by   VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (word)
 * );
 * ```
 */
final class ContentFilter
{
    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── word management ───────────────────────────────────────────────────────

    /**
     * Add a banned word/phrase (idempotent).
     *
     * @throws \InvalidArgumentException on empty word.
     */
    public function addWord(string $word, string $category = '', string $addedBy = ''): void
    {
        $word = $this->normalise($word);
        if ($word === '') {
            throw new \InvalidArgumentException('word must not be empty.');
        }

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

        $this->db()->prepare(
            "{$ignore} INTO banned_words (word, category, added_by)
             VALUES (:word, :cat, :by)"
        )->execute([':word' => $word, ':cat' => trim($category), ':by' => trim($addedBy)]);

        $this->cache = null;
    }

    /**
     * Remove a banned word/phrase.
     *
     * @return bool True if a row was deleted.
     */
    public function removeWord(string $word): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM banned_words WHERE word = :word');
        $stmt->execute([':word' => $this->normalise($word)]);
        $this->cache = null;
        return $stmt->rowCount() > 0;
    }

    /**
     * List all banned words, optionally filtered by category.
     *
     * @return list<array<string,mixed>>
     */
    public function listWords(?string $category = null): array
    {
        if ($category !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, word, category, added_by, created_at
                 FROM banned_words WHERE category = :cat ORDER BY word ASC'
            );
            $stmt->execute([':cat' => trim($category)]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, word, category, added_by, created_at
                 FROM banned_words ORDER BY word ASC'
            );
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the count of banned words.
     */
    public function wordCount(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM banned_words');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── screening ─────────────────────────────────────────────────────────────

    /**
     * Return true if the text contains at least one banned word.
     *
     * @param bool $wholeWord When true, only match on word boundaries.
     */
    public function contains(string $text, bool $wholeWord = false): bool
    {
        return $this->detect($text, $wholeWord) !== [];
    }

    /**
     * Return all banned words found in the text (deduplicated, ordered).
     *
     * @param  bool $wholeWord When true, only match on word boundaries.
     * @return list<string>
     */
    public function detect(string $text, bool $wholeWord = false): array
    {
        $words = $this->loadCache();
        if ($words === []) {
            return [];
        }
        $lowerText = mb_strtolower($text);
        $found     = [];
        foreach ($words as $w) {
            $pattern = $wholeWord ? '/\b' . preg_quote($w, '/') . '\b/iu' : null;
            if ($pattern !== null ? (bool)preg_match($pattern, $text) : str_contains($lowerText, $w)) {
                $found[] = $w;
            }
        }
        sort($found);
        return array_values(array_unique($found));
    }

    /**
     * Replace each banned word in the text with asterisks.
     *
     * @param  string $char  Replacement character (default '*').
     * @param  bool   $wholeWord When true, only replace on word boundaries.
     */
    public function mask(string $text, string $char = '*', bool $wholeWord = false): string
    {
        $words = $this->loadCache();
        if ($words === []) {
            return $text;
        }
        foreach ($words as $w) {
            $replacement = str_repeat($char, mb_strlen($w));
            if ($wholeWord) {
                $text = preg_replace('/\b' . preg_quote($w, '/') . '\b/iu', $replacement, $text) ?? $text;
            } else {
                $text = str_ireplace($w, $replacement, $text);
            }
        }
        return $text;
    }

    /**
     * Flush the in-memory word cache (call after bulk changes).
     */
    public function flushCache(): void
    {
        $this->cache = null;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function normalise(string $word): string
    {
        return mb_strtolower(trim($word));
    }

    /**
     * @return list<string>
     */
    private function loadCache(): array
    {
        if ($this->cache === null) {
            $stmt = $this->db()->prepare('SELECT word FROM banned_words ORDER BY LENGTH(word) DESC');
            $stmt->execute();
            $this->cache = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        return $this->cache;
    }
}
