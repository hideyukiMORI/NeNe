<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EntityAlias — multiple identifier aliases for an entity.
 *
 * Maps short alternative identifiers (username aliases, old slugs, redirect
 * handles, vanity URLs) to canonical entity IDs. Each alias is globally
 * unique; a transfer operation atomically reassigns an alias from one entity
 * to another. One alias per entity can be marked as primary (canonical).
 *
 * ## Usage
 *
 * ```php
 * $ea = new EntityAlias($pdo);
 *
 * // Register
 * $ea->register('user', '42', 'john-doe');
 * $ea->register('user', '42', 'johndoe', true);   // primary
 *
 * // Resolve
 * $entityId = $ea->resolve('user', 'johndoe');   // '42'
 *
 * // History / rename support
 * $ea->register('user', '42', 'j-doe');
 * $ea->setPrimary('user', '42', 'j-doe');
 *
 * // Transfer alias to another entity
 * $ea->transfer('user', 'j-doe', '99');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE entity_aliases (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     alias       VARCHAR(255) NOT NULL,
 *     is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, alias)
 * );
 * ```
 */
final class EntityAlias
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register an alias for an entity.
     *
     * If the alias already exists for the same entity the call is a no-op and
     * returns the existing row ID. If it is taken by a different entity a
     * \RuntimeException is thrown (alias uniqueness is enforced by the DB UNIQUE
     * constraint as well as an explicit pre-check).
     *
     * @param bool $isPrimary Mark this alias as the primary (canonical) handle.
     * @return int Row ID of the alias.
     * @throws \InvalidArgumentException on empty entityType/entityId/alias.
     * @throws \RuntimeException if the alias is already taken by another entity.
     */
    public function register(
        string $entityType,
        string $entityId,
        string $alias,
        bool $isPrimary = false
    ): int {
        [$entityType, $entityId, $alias] = $this->validateAll($entityType, $entityId, $alias);

        // Check whether alias is already registered
        $existing = $this->findAlias($entityType, $alias);
        if ($existing !== null) {
            if ($existing['entity_id'] === $entityId) {
                return (int)$existing['id'];   // same entity — idempotent
            }
            throw new \RuntimeException("Alias '{$alias}' is already taken.");
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO entity_aliases (entity_type, entity_id, alias, is_primary, created_at)
             VALUES (:type, :eid, :alias, :primary, :now)'
        );
        $stmt->execute([
            ':type'    => $entityType,
            ':eid'     => $entityId,
            ':alias'   => $alias,
            ':primary' => $isPrimary ? 1 : 0,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Resolve an alias to its entity ID.
     *
     * @return string|null The entity ID, or null if not found.
     */
    public function resolve(string $entityType, string $alias): ?string
    {
        $entityType = trim($entityType);
        $alias      = trim($alias);
        $row        = $this->findAlias($entityType, $alias);
        return $row !== null ? (string)$row['entity_id'] : null;
    }

    /**
     * List all aliases for an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function listAliases(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, alias, is_primary, created_at
             FROM entity_aliases
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY is_primary DESC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark an alias as the primary handle for an entity.
     *
     * Clears the primary flag on all other aliases for that entity first.
     *
     * @return bool True if the alias was found and updated.
     */
    public function setPrimary(string $entityType, string $entityId, string $alias): bool
    {
        [$entityType, $entityId, $alias] = $this->validateAll($entityType, $entityId, $alias);
        $db                              = $this->db();

        // Clear existing primary
        $db->prepare(
            'UPDATE entity_aliases SET is_primary = 0 WHERE entity_type = :type AND entity_id = :eid'
        )->execute([':type' => $entityType, ':eid' => $entityId]);

        // Set new primary
        $stmt = $db->prepare(
            'UPDATE entity_aliases SET is_primary = 1
             WHERE entity_type = :type AND entity_id = :eid AND alias = :alias'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':alias' => $alias]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a single alias.
     *
     * @return bool True if found and deleted.
     */
    public function unregister(string $entityType, string $alias): bool
    {
        $entityType = trim($entityType);
        $alias      = trim($alias);
        $stmt       = $this->db()->prepare(
            'DELETE FROM entity_aliases WHERE entity_type = :type AND alias = :alias'
        );
        $stmt->execute([':type' => $entityType, ':alias' => $alias]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Transfer an alias from its current owner to a new entity.
     *
     * @return bool True if the alias existed and was transferred.
     */
    public function transfer(string $entityType, string $alias, string $newEntityId): bool
    {
        $entityType  = trim($entityType);
        $alias       = trim($alias);
        $newEntityId = trim($newEntityId);
        if ($newEntityId === '') {
            throw new \InvalidArgumentException('new_entity_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE entity_aliases SET entity_id = :eid, is_primary = 0
             WHERE entity_type = :type AND alias = :alias'
        );
        $stmt->execute([':eid' => $newEntityId, ':type' => $entityType, ':alias' => $alias]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findAlias(string $entityType, string $alias): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM entity_aliases WHERE entity_type = :type AND alias = :alias'
        );
        $stmt->execute([':type' => $entityType, ':alias' => $alias]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array{string, string}
     */
    private function validateEntity(string $entityType, string $entityId): array
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
     * @return array{string, string, string}
     */
    private function validateAll(string $entityType, string $entityId, string $alias): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $alias = trim($alias);
        if ($alias === '') {
            throw new \InvalidArgumentException('alias must not be empty.');
        }
        return [$entityType, $entityId, $alias];
    }
}
