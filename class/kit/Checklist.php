<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Checklist — ordered checklist items attached to any entity.
 *
 * Stores ordered checklist items (e.g. onboarding steps, review checklist,
 * deployment checklist) attached to an arbitrary resource. Items have an
 * explicit position (order) and a completion state. The overall completion
 * percentage is derived from the item states.
 *
 * ## Usage
 *
 * ```php
 * $cl = new Checklist($pdo);
 *
 * // Add items
 * $id1 = $cl->addItem('issue', '42', 'Write unit tests', 1);
 * $id2 = $cl->addItem('issue', '42', 'Update documentation', 2);
 *
 * // Mark done / undone
 * $cl->check($id1);
 * $cl->uncheck($id2);
 *
 * // Query
 * $items   = $cl->items('issue', '42');
 * $pct     = $cl->percent('issue', '42');  // 50
 * $pending = $cl->pending('issue', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE checklist_items (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     label        TEXT         NOT NULL,
 *     position     INTEGER      NOT NULL DEFAULT 0,
 *     completed    TINYINT(1)   NOT NULL DEFAULT 0,
 *     completed_at DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Checklist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an item to the checklist for an entity.
     *
     * @return int Row ID of the new item.
     * @throws \InvalidArgumentException on empty label/entityType/entityId.
     */
    public function addItem(string $entityType, string $entityId, string $label, int $position = 0): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('label must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO checklist_items (entity_type, entity_id, label, position)
             VALUES (:type, :eid, :label, :pos)'
        );
        $stmt->execute([
            ':type'  => $entityType,
            ':eid'   => $entityId,
            ':label' => $label,
            ':pos'   => $position,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Mark an item as completed.
     *
     * @return bool True if found and updated.
     */
    public function check(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE checklist_items SET completed = 1, completed_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark an item as not completed.
     *
     * @return bool True if found and updated.
     */
    public function uncheck(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE checklist_items SET completed = 0, completed_at = NULL WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the label text of an item.
     *
     * @return bool True if found and updated.
     */
    public function updateLabel(int $id, string $label): bool
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('label must not be empty.');
        }
        $stmt = $this->db()->prepare('UPDATE checklist_items SET label = :label WHERE id = :id');
        $stmt->execute([':label' => $label, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a checklist item.
     *
     * @return bool True if found and deleted.
     */
    public function removeItem(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM checklist_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all checklist items for an entity.
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM checklist_items WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * List all checklist items for an entity (ordered by position, then id).
     *
     * @return list<array<string,mixed>>
     */
    public function items(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, label, position, completed, completed_at, created_at
             FROM checklist_items
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List only uncompleted items for an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function pending(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, label, position, completed, completed_at, created_at
             FROM checklist_items
             WHERE entity_type = :type AND entity_id = :eid AND completed = 0
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return completion percentage (0–100). Returns 0 if no items.
     */
    public function percent(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) AS total, SUM(completed) AS done
             FROM checklist_items
             WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int)$row['total'] === 0) {
            return 0;
        }
        return (int)round((int)$row['done'] / (int)$row['total'] * 100);
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
