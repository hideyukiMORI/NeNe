<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SavedSearch — per-user named search queries.
 *
 * Users can save search queries (filters, keywords, sort options) for quick
 * reuse. Queries are stored as opaque JSON strings so callers control the
 * schema. A name must be unique per user within an optional scope (e.g. a
 * specific search context like "products" or "orders").
 *
 * ## Usage
 *
 * ```php
 * $ss = new SavedSearch($pdo);
 *
 * // Save
 * $id = $ss->save('user-1', 'High priority open', '{"status":"open","priority":"high"}', 'issues');
 *
 * // List / get
 * $all  = $ss->forUser('user-1', 'issues');
 * $item = $ss->find($id);
 *
 * // Delete
 * $ss->delete($id, 'user-1');  // ownership-enforced
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE saved_searches (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     name       VARCHAR(255) NOT NULL,
 *     query      TEXT         NOT NULL,
 *     scope      VARCHAR(100) NOT NULL DEFAULT '',
 *     used_count INTEGER      NOT NULL DEFAULT 0,
 *     last_used  DATETIME     NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, scope, name)
 * );
 * ```
 */
final class SavedSearch
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save a named search query for a user.
     *
     * If a search with the same name already exists for the user+scope,
     * the query is updated in place (upsert).
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function save(string $userId, string $name, string $query, string $scope = ''): int
    {
        $userId = trim($userId);
        $name   = trim($name);
        $scope  = trim($scope);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        if ($query === '') {
            throw new \InvalidArgumentException('query must not be empty.');
        }

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->db()->prepare(
                'INSERT INTO saved_searches (user_id, name, query, scope)
                 VALUES (:uid, :name, :query, :scope)
                 ON DUPLICATE KEY UPDATE query = VALUES(query)'
            )->execute([':uid' => $userId, ':name' => $name, ':query' => $query, ':scope' => $scope]);
        } else {
            $this->db()->prepare(
                'INSERT INTO saved_searches (user_id, name, query, scope)
                 VALUES (:uid, :name, :query, :scope)
                 ON CONFLICT (user_id, scope, name) DO UPDATE SET query = excluded.query'
            )->execute([':uid' => $userId, ':name' => $name, ':query' => $query, ':scope' => $scope]);
        }

        // Retrieve the row ID (covers both insert and update)
        $stmt = $this->db()->prepare(
            'SELECT id FROM saved_searches WHERE user_id = :uid AND scope = :scope AND name = :name'
        );
        $stmt->execute([':uid' => $userId, ':scope' => $scope, ':name' => $name]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Find a saved search by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM saved_searches WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all saved searches for a user, optionally filtered by scope.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId, ?string $scope = null): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        if ($scope !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, name, query, scope, used_count, last_used, created_at
                 FROM saved_searches
                 WHERE user_id = :uid AND scope = :scope
                 ORDER BY name ASC'
            );
            $stmt->execute([':uid' => $userId, ':scope' => trim($scope)]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, name, query, scope, used_count, last_used, created_at
                 FROM saved_searches
                 WHERE user_id = :uid
                 ORDER BY scope ASC, name ASC'
            );
            $stmt->execute([':uid' => $userId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Record that a saved search was used (increments counter, updates last_used).
     *
     * @return bool True if found and updated.
     */
    public function recordUse(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE saved_searches SET used_count = used_count + 1, last_used = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Rename a saved search (ownership-enforced).
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty name.
     */
    public function rename(int $id, string $userId, string $newName): bool
    {
        $newName = trim($newName);
        $userId  = trim($userId);
        if ($newName === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE saved_searches SET name = :name WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':name' => $newName, ':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a saved search (ownership-enforced).
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM saved_searches WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => trim($userId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all saved searches for a user.
     *
     * @return int Number of rows deleted.
     */
    public function deleteAll(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare('DELETE FROM saved_searches WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
