<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TermGlossary — DB-backed glossary of terms and definitions.
 *
 * A simple dictionary of domain terms with optional categories, for help
 * pages, tooltips, onboarding, or "define this acronym" lookups. Terms are
 * keyed by a normalised slug (lowercased, trimmed), so `API`, `api`, and
 * ` Api ` collapse to one entry.
 *
 * ## Usage
 *
 * ```php
 * $g = new TermGlossary($pdo);
 *
 * $g->define('API', 'Application Programming Interface', 'tech');
 * $g->define('SLA', 'Service Level Agreement', 'ops');
 *
 * $g->get('api');                 // ['term'=>'API','definition'=>'...','category'=>'tech']
 * $g->has('API');                 // true
 * $g->search('interface');        // matches term or definition
 * $g->byCategory('tech');         // entries in a category
 * $g->categories();               // ['ops', 'tech']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE glossary_terms (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     slug       VARCHAR(190) NOT NULL,
 *     term       VARCHAR(190) NOT NULL,
 *     definition TEXT         NOT NULL,
 *     category   VARCHAR(100) NOT NULL DEFAULT '',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (slug)
 * );
 * ```
 */
final class TermGlossary
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define (or redefine) a term. Idempotent per normalised term.
     *
     * @param  string $term       Display term (e.g. 'API').
     * @param  string $definition Definition text.
     * @param  string $category   Optional category.
     * @throws \InvalidArgumentException on empty term or definition.
     */
    public function define(string $term, string $definition, string $category = ''): void
    {
        $slug = $this->slug($term);
        $term = trim($term);
        if (trim($definition) === '') {
            throw new \InvalidArgumentException('Definition must not be empty.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'glossary_terms',
            data:         ['slug' => $slug, 'term' => $term, 'definition' => $definition, 'category' => trim($category)],
            conflictCols: ['slug'],
            updateCols:   ['term', 'definition', 'category'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Look up a term (case-insensitive).
     *
     * @return array{term:string,definition:string,category:string}|null
     */
    public function get(string $term): ?array
    {
        $stmt = $this->db()->prepare('SELECT term, definition, category FROM glossary_terms WHERE slug = ?');
        $stmt->execute([$this->slug($term)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Whether a term is defined (case-insensitive).
     */
    public function has(string $term): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM glossary_terms WHERE slug = ? LIMIT 1');
        $stmt->execute([$this->slug($term)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Search terms and definitions for a substring (case-insensitive), term order.
     *
     * @return array<int,array{term:string,definition:string,category:string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like = '%' . $this->escapeLike(mb_strtolower($query)) . '%';

        $stmt = $this->db()->prepare(
            "SELECT term, definition, category FROM glossary_terms
             WHERE LOWER(term) LIKE ? ESCAPE '\\' OR LOWER(definition) LIKE ? ESCAPE '\\'
             ORDER BY term ASC"
        );
        $stmt->execute([$like, $like]);

        return $this->hydrateRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * List entries in a category, term order.
     *
     * @return array<int,array{term:string,definition:string,category:string}>
     */
    public function byCategory(string $category): array
    {
        $stmt = $this->db()->prepare(
            'SELECT term, definition, category FROM glossary_terms WHERE category = ? ORDER BY term ASC'
        );
        $stmt->execute([trim($category)]);

        return $this->hydrateRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * List distinct non-empty categories, alphabetically.
     *
     * @return array<int,string>
     */
    public function categories(): array
    {
        $stmt = $this->db()->query(
            "SELECT DISTINCT category FROM glossary_terms WHERE category <> '' ORDER BY category ASC"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn ($c): string => (string)$c, $rows);
    }

    /**
     * List all entries, term order.
     *
     * @return array<int,array{term:string,definition:string,category:string}>
     */
    public function all(): array
    {
        $stmt = $this->db()->query('SELECT term, definition, category FROM glossary_terms ORDER BY term ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    /**
     * Number of defined terms.
     */
    public function count(): int
    {
        $stmt = $this->db()->query('SELECT COUNT(*) FROM glossary_terms');

        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    }

    /**
     * Remove a term (case-insensitive). No-op if absent.
     */
    public function remove(string $term): void
    {
        $stmt = $this->db()->prepare('DELETE FROM glossary_terms WHERE slug = ?');
        $stmt->execute([$this->slug($term)]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function slug(string $term): string
    {
        $slug = mb_strtolower(trim($term));
        if ($slug === '') {
            throw new \InvalidArgumentException('Term must not be empty.');
        }

        return $slug;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array{term:string,definition:string,category:string}>
     */
    private function hydrateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $row
     * @return array{term:string,definition:string,category:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'term'       => (string)$row['term'],
            'definition' => (string)$row['definition'],
            'category'   => (string)$row['category'],
        ];
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
