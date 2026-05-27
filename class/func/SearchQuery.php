<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Func;

/**
 * Search query helpers for full-text and LIKE-based search.
 *
 * NeNe supports two search approaches:
 *  - SQLite FTS5: virtual table + MATCH query (best for content-heavy search)
 *  - MySQL LIKE: LIKE '%term%' with proper escaping (simplest, no schema change)
 *
 * Use FTS5 for full-text search on large text fields.
 * Use LIKE for simple substring matching on short fields.
 */
final class SearchQuery
{
    /**
     * Escape a string for use in a SQL LIKE pattern.
     *
     * Escapes %, _, and \ with a backslash.
     * Wrap the result in % for substring matching: '%' . escape($term) . '%'
     *
     * @param string $term Raw search term from user input.
     * @return string Escaped string safe for LIKE patterns.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Build a LIKE pattern for substring matching.
     *
     * @param string $term  Raw user input.
     * @param string $mode  'contains' | 'starts-with' | 'ends-with'
     */
    public static function likePattern(string $term, string $mode = 'contains'): string
    {
        $escaped = self::escapeLike($term);
        return match ($mode) {
            'starts-with' => $escaped . '%',
            'ends-with'   => '%' . $escaped,
            default       => '%' . $escaped . '%',
        };
    }

    /**
     * Sanitize a search term for SQLite FTS5 MATCH queries.
     *
     * Strips characters that cause FTS5 syntax errors (unmatched quotes, etc.).
     * For complex FTS5 queries (phrase search, AND/OR), build them manually.
     *
     * @param string $term Raw user input.
     * @return string Safe search string for FTS5 MATCH.
     */
    public static function sanitizeFts(string $term): string
    {
        // Remove double quotes (cause "unclosed string" errors in FTS5)
        $term = str_replace('"', '', $term);
        // Remove FTS5 operator keywords that can cause unexpected behaviour when
        // submitted as literal content (NOT, AND, OR, NEAR)
        // Trim leading/trailing whitespace
        return trim($term);
    }

    /**
     * Normalize a search string: collapse whitespace, remove null bytes, trim.
     *
     * @param string $input Raw user input.
     * @return string Normalized search string.
     */
    public static function normalize(string $input): string
    {
        $input = str_replace("\0", ' ', $input);           // null bytes → space
        $input = (string) preg_replace('/\s+/u', ' ', $input); // collapse whitespace
        return trim($input);
    }
}
