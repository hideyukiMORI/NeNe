<?php

declare(strict_types=1);

namespace Nene\Xion;

use Monolog\Logger;
use PDO;

/**
 * Append-only audit log writer.
 *
 * Records actor / action / resource mutations to an `audit_log` table.
 * The table is intentionally write-only via this class; only SELECT via
 * SQL or a dedicated read mapper is supported. Never add UPDATE/DELETE
 * routes for audit records — immutability is the point.
 *
 * ## Schema (add to SchemaDefinition or SQL migration)
 *
 * ```sql
 * CREATE TABLE audit_log (
 *     id            BIGINT AUTO_INCREMENT PRIMARY KEY,
 *     actor_id      BIGINT       NOT NULL,
 *     action        VARCHAR(64)  NOT NULL,   -- 'created' | 'updated' | 'deleted' | custom
 *     resource_type VARCHAR(64)  NOT NULL,   -- e.g. 'task', 'user', 'order'
 *     resource_id   BIGINT       NOT NULL,
 *     payload       TEXT         NOT NULL,   -- JSON: snapshot / before+after / notes
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 *
 * ## Typical usage
 *
 * ```php
 * $audit = new AuditLogger();
 *
 * // Create
 * $task = $this->taskMapper->create($title, $body, $actorId);
 * $audit->record($actorId, 'created', 'task', $task->id, ['title' => $title]);
 *
 * // Update (before/after)
 * $before = $this->taskMapper->find($id);
 * $after  = $this->taskMapper->update($id, $newData);
 * $audit->record($actorId, 'updated', 'task', $id, [
 *     'before' => ['title' => $before->title],
 *     'after'  => ['title' => $after->title],
 * ]);
 *
 * // Delete
 * $task = $this->taskMapper->find($id);
 * $this->taskMapper->delete($task);
 * $audit->record($actorId, 'deleted', 'task', $id, ['title' => $task->title]);
 * ```
 */
final class AuditLogger
{
    private const TABLE = 'audit_log';

    private PDO $db;

    private Logger $logger;

    /**
     * @param string      $table  Override the default table name (for testing or multi-tenant use).
     * @param PDO|null    $db     Database connection. Defaults to PdoConnection::getInstance().
     * @param Logger|null $logger Logger instance. Defaults to Log::getInstance().
     */
    public function __construct(
        private readonly string $table = self::TABLE,
        ?PDO $db = null,
        ?Logger $logger = null,
    ) {
        $this->db     = $db     ?? PdoConnection::getInstance();
        $this->logger = $logger ?? Log::getInstance();
    }

    /**
     * Append an audit record.
     *
     * @param int                 $actorId      ID of the user performing the action.
     * @param string              $action       Action name: 'created', 'updated', 'deleted', or custom.
     * @param string              $resourceType Resource type name (e.g. 'task', 'order').
     * @param int                 $resourceId   Resource primary key.
     * @param array<string,mixed> $payload      Arbitrary context (snapshot, before/after, notes).
     *                                          Do NOT include passwords or other secrets.
     *
     * @return void
     */
    public function record(
        int $actorId,
        string $action,
        string $resourceType,
        int $resourceId,
        array $payload = [],
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $this->table .
                ' (actor_id, action, resource_type, resource_id, payload)' .
                ' VALUES (:actor_id, :action, :resource_type, :resource_id, :payload)'
            );
            $stmt->bindValue(':actor_id',      $actorId,                                                          PDO::PARAM_INT);
            $stmt->bindValue(':action',        $action,                                                           PDO::PARAM_STR);
            $stmt->bindValue(':resource_type', $resourceType,                                                     PDO::PARAM_STR);
            $stmt->bindValue(':resource_id',   $resourceId,                                                       PDO::PARAM_INT);
            $stmt->bindValue(':payload',       json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PDO::PARAM_STR);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Audit failures must not break the primary operation.
            // Log the error and continue.
            $this->logger->error('AuditLogger: failed to record audit entry.', [
                'exception'     => $e->getMessage(),
                'actor_id'      => $actorId,
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
            ]);
        }
    }
}
