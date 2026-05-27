<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Simple DB-backed job queue for lightweight background task processing.
 *
 * Jobs are stored as serialized payloads with status tracking.
 * A worker calls `dequeue()` to claim the next available job (atomic),
 * then processes it, then calls `complete()` or `fail()`.
 * Failed jobs can be retried up to a configurable limit.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE job_queue (
 *     id           INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     queue        VARCHAR(64)  NOT NULL DEFAULT 'default',
 *     payload      TEXT         NOT NULL,
 *     status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
 *     attempts     INTEGER      NOT NULL DEFAULT 0,
 *     max_attempts INTEGER      NOT NULL DEFAULT 3,
 *     error        TEXT         DEFAULT NULL,
 *     available_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_job_queue_dequeue ON job_queue (queue, status, available_at);
 * ```
 *
 * ## Status values
 *
 * - `pending`    — waiting to be picked up.
 * - `processing` — claimed by a worker; being executed.
 * - `completed`  — finished successfully.
 * - `failed`     — all attempts exhausted.
 *
 * ## Usage
 *
 * ```php
 * $q = new JobQueue($pdo);
 *
 * // Enqueue
 * $id = $q->enqueue(['type' => 'send_email', 'to' => 'user@example.com']);
 *
 * // Worker loop
 * while ($job = $q->dequeue()) {
 *     try {
 *         $payload = $job['payload']; // decoded array
 *         // ... process ...
 *         $q->complete($job['id']);
 *     } catch (\Throwable $e) {
 *         $q->fail($job['id'], $e->getMessage());
 *     }
 * }
 * ```
 */
final class JobQueue
{
    private const TABLE           = 'job_queue';
    private const DEFAULT_QUEUE   = 'default';
    private const STATUS_PENDING  = 'pending';
    private const STATUS_PROCESS  = 'processing';
    private const STATUS_COMPLETE = 'completed';
    private const STATUS_FAILED   = 'failed';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Add a job to the queue.
     *
     * @param  array<mixed> $payload     Job data (will be JSON-encoded).
     * @param  string       $queue       Queue name (default: 'default').
     * @param  int          $maxAttempts Maximum number of delivery attempts.
     * @param  int          $delaySeconds Seconds before job becomes available.
     * @return int New job ID.
     */
    public function enqueue(
        array $payload,
        string $queue = self::DEFAULT_QUEUE,
        int $maxAttempts = 3,
        int $delaySeconds = 0,
    ): int {
        $db          = $this->db ?? PdoConnection::getInstance();
        $now         = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $availableAt = $delaySeconds > 0
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $delaySeconds . ' seconds')
                ->format('Y-m-d H:i:s')
            : $now;

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (queue, payload, status, max_attempts, available_at, created_at, updated_at)' .
            ' VALUES (:q, :payload, :status, :max, :avail, :now, :now)'
        );
        $stmt->bindValue(':q', $queue, PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':avail', $availableAt, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Claim the next available job from a queue (atomic).
     *
     * Returns null when the queue is empty.
     * The returned payload is decoded from JSON.
     *
     * @param  string $queue Queue name.
     * @return array{id:int,queue:string,payload:array<mixed>,attempts:int,max_attempts:int,created_at:string}|null
     */
    public function dequeue(string $queue = self::DEFAULT_QUEUE): ?array
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, queue, payload, attempts, max_attempts, created_at' .
                ' FROM ' . $this->table .
                ' WHERE queue = :q AND status = :status AND available_at <= :now' .
                ' ORDER BY id ASC LIMIT 1'
            );
            $stmt->bindValue(':q', $queue, PDO::PARAM_STR);
            $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);
            $stmt->bindValue(':now', $now, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $db->rollBack();
                return null;
            }

            $upd = $db->prepare(
                'UPDATE ' . $this->table .
                ' SET status = :status, attempts = attempts + 1, updated_at = :now' .
                ' WHERE id = :id'
            );
            $upd->bindValue(':status', self::STATUS_PROCESS, PDO::PARAM_STR);
            $upd->bindValue(':now', $now, PDO::PARAM_STR);
            $upd->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
            $upd->execute();

            $db->commit();

            return [
                'id'           => (int)$row['id'],
                'queue'        => (string)$row['queue'],
                'payload'      => json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'attempts'     => (int)$row['attempts'] + 1,
                'max_attempts' => (int)$row['max_attempts'],
                'created_at'   => (string)$row['created_at'],
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a job as successfully completed.
     *
     * @param int $jobId Job ID returned by dequeue().
     */
    public function complete(int $jobId): void
    {
        $this->updateStatus($jobId, self::STATUS_COMPLETE);
    }

    /**
     * Mark a job as failed. If attempts < max_attempts, re-queues it as pending.
     *
     * @param int    $jobId   Job ID returned by dequeue().
     * @param string $error   Error message to record.
     */
    public function fail(int $jobId, string $error = ''): void
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Check if retries remain
        $stmt = $db->prepare(
            'SELECT attempts, max_attempts FROM ' . $this->table . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return;
        }

        $newStatus = (int)$row['attempts'] >= (int)$row['max_attempts']
            ? self::STATUS_FAILED
            : self::STATUS_PENDING;

        $upd = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET status = :status, error = :error, updated_at = :now WHERE id = :id'
        );
        $upd->bindValue(':status', $newStatus, PDO::PARAM_STR);
        $upd->bindValue(':error', $error !== '' ? $error : null, $error !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $upd->bindValue(':now', $now, PDO::PARAM_STR);
        $upd->bindValue(':id', $jobId, PDO::PARAM_INT);
        $upd->execute();
    }

    /**
     * Get the number of jobs by status in a queue.
     *
     * @param  string      $queue  Queue name.
     * @param  string|null $status Filter by status. Null = all statuses.
     * @return int
     */
    public function count(string $queue = self::DEFAULT_QUEUE, ?string $status = null): int
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $sql = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE queue = :q';
        if ($status !== null) {
            $sql .= ' AND status = :status';
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':q', $queue, PDO::PARAM_STR);
        if ($status !== null) {
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function updateStatus(int $jobId, string $status): void
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table . ' SET status = :status, updated_at = :now WHERE id = :id'
        );
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }
}
