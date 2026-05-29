<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SlugRegistry — URL slug generation and uniqueness enforcement.
 *
 * Generates URL-friendly slugs from text and ensures uniqueness within a
 * namespace. If the desired slug is already taken, a numeric suffix is
 * appended (e.g. `my-post`, `my-post-2`, `my-post-3`).
 *
 * Each slug is bound to an entity (entity_type + entity_id), enabling
 * reverse lookup and slug reassignment.
 *
 * ## Usage
 *
 * ```php
 * $sr = new SlugRegistry($pdo);
 *
 * // Register a slug (auto-generates if taken)
 * $slug = $sr->register('post', '42', 'Hello World!');  // 'hello-world'
 * $slug = $sr->register('post', '43', 'Hello World!');  // 'hello-world-2'
 *
 * // Resolve slug → entity
 * $sr->resolve('post', 'hello-world');  // ['entity_id' => '42', ...]
 *
 * // Find entity's current slug
 * $sr->forEntity('post', '42');  // 'hello-world'
 *
 * // Release a slug
 * $sr->release('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE slug_registry (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     namespace   VARCHAR(100) NOT NULL,
 *     slug        VARCHAR(255) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (namespace, slug),
 *     UNIQUE (namespace, entity_id)
 * );
 * ```
 */
final class SlugRegistry
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register a slug for an entity.
     *
     * Generates a URL-safe slug from $text. If the slug is already taken by a
     * different entity, a numeric suffix is appended until a free slot is found.
     * If the entity already has a slug, it is replaced.
     *
     * @return string The registered slug.
     * @throws \InvalidArgumentException on empty namespace, entity_id, or text.
     */
    public function register(string $namespace, string $entityId, string $text): string
    {
        [$namespace, $entityId] = $this->validateNsEntity($namespace, $entityId);
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('text must not be empty.');
        }

        $base = $this->slugify($text);
        $slug = $base;
        $n    = 1;
        $db   = $this->db();

        // Release any existing slug for this entity first
        $db->prepare(
            'DELETE FROM slug_registry WHERE namespace = :ns AND entity_id = :eid'
        )->execute([':ns' => $namespace, ':eid' => $entityId]);

        // Find a free slug
        while (true) {
            $check = $db->prepare(
                'SELECT COUNT(*) FROM slug_registry WHERE namespace = :ns AND slug = :slug'
            );
            $check->execute([':ns' => $namespace, ':slug' => $slug]);
            if ((int)$check->fetchColumn() === 0) {
                break;
            }
            $n++;
            $slug = "{$base}-{$n}";
        }

        $db->prepare(
            'INSERT INTO slug_registry (namespace, slug, entity_id) VALUES (:ns, :slug, :eid)'
        )->execute([':ns' => $namespace, ':slug' => $slug, ':eid' => $entityId]);

        return $slug;
    }

    /**
     * Resolve a slug to its entity record.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $namespace, string $slug): ?array
    {
        $namespace = trim($namespace);
        $stmt      = $this->db()->prepare(
            'SELECT id, namespace, slug, entity_id, created_at
             FROM slug_registry WHERE namespace = :ns AND slug = :slug LIMIT 1'
        );
        $stmt->execute([':ns' => $namespace, ':slug' => trim($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the current slug for an entity.
     *
     * @return string|null null if the entity has no registered slug.
     */
    public function forEntity(string $namespace, string $entityId): ?string
    {
        [$namespace, $entityId] = $this->validateNsEntity($namespace, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT slug FROM slug_registry WHERE namespace = :ns AND entity_id = :eid LIMIT 1'
        );
        $stmt->execute([':ns' => $namespace, ':eid' => $entityId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Release an entity's slug.
     *
     * @return bool True if a slug was released.
     */
    public function release(string $namespace, string $entityId): bool
    {
        [$namespace, $entityId] = $this->validateNsEntity($namespace, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM slug_registry WHERE namespace = :ns AND entity_id = :eid'
        );
        $stmt->execute([':ns' => $namespace, ':eid' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a slug is already taken in a namespace.
     */
    public function isTaken(string $namespace, string $slug): bool
    {
        $namespace = trim($namespace);
        $stmt      = $this->db()->prepare(
            'SELECT COUNT(*) FROM slug_registry WHERE namespace = :ns AND slug = :slug'
        );
        $stmt->execute([':ns' => $namespace, ':slug' => trim($slug)]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count registered slugs in a namespace.
     */
    public function count(string $namespace = ''): int
    {
        $namespace = trim($namespace);
        if ($namespace === '') {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM slug_registry');
            $stmt->execute();
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM slug_registry WHERE namespace = :ns');
            $stmt->execute([':ns' => $namespace]);
        }
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * Convert text to a URL-safe slug.
     * Lowercase, replace non-alphanumeric runs with hyphens, trim hyphens.
     */
    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        return trim($text, '-') ?: 'slug';
    }

    /**
     * @return array{string, string}
     */
    private function validateNsEntity(string $namespace, string $entityId): array
    {
        $namespace = trim($namespace);
        $entityId  = trim($entityId);
        if ($namespace === '') {
            throw new \InvalidArgumentException('namespace must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$namespace, $entityId];
    }
}
