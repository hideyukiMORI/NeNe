<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * WorkflowInstance — persistent stateful workflow tracking.
 *
 * Stores running instances of named workflows attached to arbitrary entities.
 * Each instance has a current state plus a transition history. The host
 * application is responsible for defining which state transitions are valid
 * (e.g. via WorkflowDefinition); WorkflowInstance only persists the state.
 *
 * ## Usage
 *
 * ```php
 * $wf = new WorkflowInstance($pdo);
 *
 * $id = $wf->create('order', 'order-42', 'pending', ['items' => 3]);
 * $wf->transition($id, 'processing', 'user-7');
 * $wf->transition($id, 'shipped',    'user-7', ['tracking' => 'TRK123']);
 * $wf->complete($id, 'user-7');
 *
 * $history = $wf->history($id);
 * $pct     = count(array_filter($history, fn($r) => $r['to_state'] === 'shipped'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE workflow_instances (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     type          VARCHAR(100) NOT NULL,
 *     entity_ref    VARCHAR(255) NOT NULL,
 *     current_state VARCHAR(100) NOT NULL,
 *     context       TEXT         NULL,
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE workflow_transitions (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     instance_id     INTEGER      NOT NULL,
 *     from_state      VARCHAR(100) NOT NULL,
 *     to_state        VARCHAR(100) NOT NULL,
 *     actor_id        VARCHAR(255) NULL,
 *     metadata        TEXT         NULL,
 *     transitioned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class WorkflowInstance
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new workflow instance.
     *
     * @param array<string,mixed>|null $context Optional JSON-serialisable context.
     * @return int New instance ID.
     * @throws \InvalidArgumentException on empty type/entityRef/initialState.
     */
    public function create(
        string $type,
        string $entityRef,
        string $initialState,
        ?array $context = null
    ): int {
        $type         = trim($type);
        $entityRef    = trim($entityRef);
        $initialState = trim($initialState);
        if ($type === '') {
            throw new \InvalidArgumentException('type must not be empty.');
        }
        if ($entityRef === '') {
            throw new \InvalidArgumentException('entity_ref must not be empty.');
        }
        if ($initialState === '') {
            throw new \InvalidArgumentException('initial_state must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO workflow_instances (type, entity_ref, current_state, context, status, created_at, updated_at)
             VALUES (:type, :ref, :state, :ctx, :status, :now, :now)'
        );
        $stmt->execute([
            ':type'   => $type,
            ':ref'    => $entityRef,
            ':state'  => $initialState,
            ':ctx'    => $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            ':status' => self::STATUS_ACTIVE,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a workflow instance row.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM workflow_instances WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the current state string, or null if the instance does not exist.
     */
    public function state(int $id): ?string
    {
        $row = $this->get($id);
        return $row !== null ? (string)$row['current_state'] : null;
    }

    /**
     * Record a state transition.
     *
     * Only transitions instances with STATUS_ACTIVE. Returns false if the
     * instance is not found or already completed/cancelled.
     *
     * @param array<string,mixed>|null $metadata Optional transition metadata.
     * @return bool True if transitioned.
     * @throws \InvalidArgumentException on empty newState.
     */
    public function transition(
        int $id,
        string $newState,
        ?string $actorId = null,
        ?array $metadata = null
    ): bool {
        $newState = trim($newState);
        if ($newState === '') {
            throw new \InvalidArgumentException('new_state must not be empty.');
        }

        $row = $this->get($id);
        if ($row === null || $row['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = $this->db();

        // Record transition history
        $stmt = $db->prepare(
            'INSERT INTO workflow_transitions (instance_id, from_state, to_state, actor_id, metadata, transitioned_at)
             VALUES (:iid, :from, :to, :actor, :meta, :now)'
        );
        $stmt->execute([
            ':iid'   => $id,
            ':from'  => $row['current_state'],
            ':to'    => $newState,
            ':actor' => $actorId,
            ':meta'  => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ':now'   => $now,
        ]);

        // Update instance state
        $upd = $db->prepare(
            'UPDATE workflow_instances SET current_state = :state, updated_at = :now WHERE id = :id'
        );
        $upd->execute([':state' => $newState, ':now' => $now, ':id' => $id]);
        return true;
    }

    /**
     * Mark the instance as completed.
     *
     * @return bool True if found and marked.
     */
    public function complete(int $id, ?string $actorId = null): bool
    {
        return $this->setTerminalStatus($id, self::STATUS_COMPLETED, $actorId);
    }

    /**
     * Cancel the instance.
     *
     * @return bool True if found and cancelled.
     */
    public function cancel(int $id, ?string $actorId = null, ?string $reason = null): bool
    {
        $meta = $reason !== null ? ['reason' => $reason] : null;
        return $this->setTerminalStatus($id, self::STATUS_CANCELLED, $actorId, $meta);
    }

    /**
     * Return all transition records for an instance (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $id): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, instance_id, from_state, to_state, actor_id, metadata, transitioned_at
             FROM workflow_transitions
             WHERE instance_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return active instances for a given entity reference.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $type, string $entityRef): array
    {
        $type      = trim($type);
        $entityRef = trim($entityRef);
        $stmt      = $this->db()->prepare(
            'SELECT * FROM workflow_instances
             WHERE type = :type AND entity_ref = :ref AND status = :status
             ORDER BY id DESC'
        );
        $stmt->execute([':type' => $type, ':ref' => $entityRef, ':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete an instance and its transition history (hard delete).
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM workflow_transitions WHERE instance_id = :id')->execute([':id' => $id]);
        $stmt = $db->prepare('DELETE FROM workflow_instances WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    private function setTerminalStatus(
        int $id,
        string $status,
        ?string $actorId,
        ?array $meta = null
    ): bool {
        $row = $this->get($id);
        if ($row === null || $row['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = $this->db();

        // Record terminal transition
        $stmt = $db->prepare(
            'INSERT INTO workflow_transitions (instance_id, from_state, to_state, actor_id, metadata, transitioned_at)
             VALUES (:iid, :from, :to, :actor, :meta, :now)'
        );
        $stmt->execute([
            ':iid'   => $id,
            ':from'  => $row['current_state'],
            ':to'    => $status,
            ':actor' => $actorId,
            ':meta'  => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ':now'   => $now,
        ]);

        $upd = $db->prepare(
            'UPDATE workflow_instances SET status = :status, updated_at = :now WHERE id = :id'
        );
        $upd->execute([':status' => $status, ':now' => $now, ':id' => $id]);
        return true;
    }
}
