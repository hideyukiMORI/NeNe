<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Label — colored label definitions with entity assignment.
 *
 * Two-table design: `labels` defines reusable labels (name + color);
 * `label_assignments` attaches labels to arbitrary entities. Multiple
 * labels may be attached to one entity. Unlike TagIndex (which uses
 * plain strings for multi-tag AND queries), Label stores visual metadata
 * (color) and owns the label lifecycle.
 *
 * ## Usage
 *
 * ```php
 * $lbl = new Label($pdo);
 *
 * // Define labels
 * $id = $lbl->create('bug', '#e11d48');
 * $lbl->create('feature', '#2563eb');
 *
 * // Assign to entities
 * $lbl->attach('issue', '42', $id);
 *
 * // Query
 * $labels = $lbl->forEntity('issue', '42');
 * $issues = $lbl->entitiesWithLabel($id);
 *
 * // Detach / manage
 * $lbl->detach('issue', '42', $id);
 * $lbl->detachAll('issue', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE labels (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(100) NOT NULL UNIQUE,
 *     color      VARCHAR(20)  NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE label_assignments (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     label_id    INTEGER      NOT NULL,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (label_id, entity_type, entity_id)
 * );
 * ```
 */
final class Label
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── label management ──────────────────────────────────────────────────────

    /**
     * Create a new label.
     *
     * @return int Row ID of the new label.
     * @throws \InvalidArgumentException on empty name.
     */
    public function create(string $name, string $color = ''): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'INSERT INTO labels (name, color) VALUES (:name, :color)'
        );
        $stmt->execute([':name' => $name, ':color' => trim($color)]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Update a label's name and/or color.
     *
     * @return bool True if found and updated.
     */
    public function update(int $id, string $name, string $color = ''): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE labels SET name = :name, color = :color WHERE id = :id'
        );
        $stmt->execute([':name' => $name, ':color' => trim($color), ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a label and all its assignments.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $this->db()->prepare('DELETE FROM label_assignments WHERE label_id = :id')->execute([':id' => $id]);
        $stmt = $this->db()->prepare('DELETE FROM labels WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a label by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, color, created_at FROM labels WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a label by name.
     *
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, color, created_at FROM labels WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => trim($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all labels ordered by name.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare('SELECT id, name, color, created_at FROM labels ORDER BY name ASC');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── assignments ───────────────────────────────────────────────────────────

    /**
     * Attach a label to an entity (idempotent).
     *
     * @throws \InvalidArgumentException on empty entityType/entityId.
     */
    public function attach(string $entityType, string $entityId, int $labelId): void
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $db = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT OR IGNORE INTO label_assignments (label_id, entity_type, entity_id)
                 VALUES (:lid, :type, :eid)'
            )->execute([':lid' => $labelId, ':type' => $entityType, ':eid' => $entityId]);
        } else {
            $db->prepare(
                'INSERT IGNORE INTO label_assignments (label_id, entity_type, entity_id)
                 VALUES (:lid, :type, :eid)'
            )->execute([':lid' => $labelId, ':type' => $entityType, ':eid' => $entityId]);
        }
    }

    /**
     * Detach a label from an entity.
     *
     * @return bool True if the assignment existed and was removed.
     */
    public function detach(string $entityType, string $entityId, int $labelId): bool
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM label_assignments WHERE label_id = :lid AND entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':lid' => $labelId, ':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Detach all labels from an entity.
     *
     * @return int Number of assignments removed.
     */
    public function detachAll(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM label_assignments WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * List all labels attached to an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT l.id, l.name, l.color, l.created_at
             FROM labels l
             INNER JOIN label_assignments la ON la.label_id = l.id
             WHERE la.entity_type = :type AND la.entity_id = :eid
             ORDER BY l.name ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all entity_type+entity_id pairs that carry a given label.
     *
     * @return list<array{entity_type:string,entity_id:string,assigned_at:string}>
     */
    public function entitiesWithLabel(int $labelId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT entity_type, entity_id, assigned_at FROM label_assignments
             WHERE label_id = :lid ORDER BY assigned_at DESC'
        );
        $stmt->execute([':lid' => $labelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check whether a specific label is attached to an entity.
     */
    public function hasLabel(string $entityType, string $entityId, int $labelId): bool
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM label_assignments
             WHERE label_id = :lid AND entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':lid' => $labelId, ':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
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
}
