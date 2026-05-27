<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * WebhookDelivery — outbound webhook delivery log with retry tracking.
 *
 * Records every delivery attempt for an outbound webhook call.
 * Tracks HTTP status, response snippet, attempt count, and next retry time.
 *
 * The caller is responsible for making the actual HTTP request;
 * this class only stores the delivery record and manages retry state.
 *
 * Status lifecycle: `pending` → `delivered` | `failed` (after max retries)
 *
 * ## Usage
 *
 * ```php
 * $wd = new WebhookDelivery($pdo);
 *
 * // Schedule a delivery
 * $id = $wd->schedule('endpoint-1', 'user.created', ['id' => 42]);
 *
 * // Claim next pending delivery for sending
 * $delivery = $wd->claimNext();
 *
 * // Record success
 * $wd->succeed($delivery['id'], 200, 'ok');
 *
 * // Record failure (will auto-schedule retry)
 * $wd->fail($delivery['id'], 0, 'Connection refused');
 *
 * // Pending deliveries for an endpoint
 * $wd->listPending('endpoint-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE webhook_deliveries (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     endpoint_id   VARCHAR(255) NOT NULL,
 *     event_type    VARCHAR(100) NOT NULL,
 *     payload       TEXT         NOT NULL DEFAULT '{}',
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     attempts      INT          NOT NULL DEFAULT 0,
 *     max_attempts  INT          NOT NULL DEFAULT 5,
 *     http_status   INT          NOT NULL DEFAULT 0,
 *     response_body TEXT         NOT NULL DEFAULT '',
 *     next_attempt_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     delivered_at  DATETIME     DEFAULT NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class WebhookDelivery
{
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $maxAttempts = 5,
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Schedule a new webhook delivery.
     *
     * @param  array<string,mixed> $payload      Event payload to deliver.
     * @param  int                 $delaySeconds Delay before first attempt.
     * @return int The new delivery ID.
     * @throws \InvalidArgumentException if endpoint_id or event_type is empty.
     */
    public function schedule(
        string $endpointId,
        string $eventType,
        array $payload = [],
        int $delaySeconds = 0
    ): int {
        [$endpointId, $eventType] = $this->normalise($endpointId, $eventType);
        $db  = $this->db();
        $at  = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s');

        $db->prepare(
            'INSERT INTO webhook_deliveries (endpoint_id, event_type, payload, max_attempts, next_attempt_at)
             VALUES (:endpoint, :event, :payload, :max, :at)'
        )->execute([
            ':endpoint' => $endpointId,
            ':event'    => $eventType,
            ':payload'  => json_encode($payload, JSON_THROW_ON_ERROR),
            ':max'      => $this->maxAttempts,
            ':at'       => $at,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Claim the next pending delivery ready to be sent.
     *
     * Atomically transitions status to `sending` and increments attempts.
     * Returns null if nothing is ready.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $db  = $this->db();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            "SELECT id FROM webhook_deliveries
             WHERE status = 'pending' AND next_attempt_at <= :now
             ORDER BY next_attempt_at ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute([':now' => $now]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $stmt = $db->prepare(
            "UPDATE webhook_deliveries
             SET status = 'sending', attempts = attempts + 1
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => (int)$id]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->find((int)$id);
    }

    /**
     * Record a successful delivery.
     *
     * @return bool True if the delivery was in `sending` status.
     */
    public function succeed(int $deliveryId, int $httpStatus, string $responseBody = ''): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE webhook_deliveries
             SET status = 'delivered', http_status = :code, response_body = :body,
                 delivered_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'sending'"
        );
        $stmt->execute([':code' => $httpStatus, ':body' => $responseBody, ':id' => $deliveryId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Record a failed delivery attempt.
     *
     * If attempts < max_attempts the delivery is rescheduled with exponential backoff.
     * Otherwise it is permanently marked `failed`.
     *
     * @return bool True if the delivery was in `sending` status.
     */
    public function fail(int $deliveryId, int $httpStatus = 0, string $responseBody = ''): bool
    {
        $db   = $this->db();
        $stmt = $db->prepare(
            "SELECT attempts, max_attempts FROM webhook_deliveries WHERE id = :id AND status = 'sending'"
        );
        $stmt->execute([':id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        if ((int)$row['attempts'] < (int)$row['max_attempts']) {
            // Exponential backoff: 1min, 2min, 4min, 8min, …
            $delay = 60 * (2 ** ((int)$row['attempts'] - 1));
            $next  = (new \DateTimeImmutable())->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
            $db->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'pending', http_status = :code, response_body = :body,
                     next_attempt_at = :next
                 WHERE id = :id"
            )->execute([':code' => $httpStatus, ':body' => $responseBody, ':next' => $next, ':id' => $deliveryId]);
        } else {
            $db->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'failed', http_status = :code, response_body = :body
                 WHERE id = :id"
            )->execute([':code' => $httpStatus, ':body' => $responseBody, ':id' => $deliveryId]);
        }

        return true;
    }

    /**
     * Get a single delivery by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $deliveryId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, endpoint_id, event_type, payload, status, attempts, max_attempts,
                    http_status, response_body, next_attempt_at, delivered_at, created_at
             FROM webhook_deliveries WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode((string)$row['payload'], true) ?? [];
        return $row;
    }

    /**
     * List pending deliveries for an endpoint (or all endpoints if null).
     *
     * @return list<array<string,mixed>>
     */
    public function listPending(string $endpointId, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt  = $this->db()->prepare(
            "SELECT id, endpoint_id, event_type, payload, status, attempts, next_attempt_at
             FROM webhook_deliveries
             WHERE endpoint_id = :endpoint AND status = 'pending' AND next_attempt_at <= :now
             ORDER BY next_attempt_at ASC, id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':endpoint' => $endpointId, ':now' => $now]);
        return array_map(
            static function (array $row): array {
                $row['payload'] = json_decode((string)$row['payload'], true) ?? [];
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Count deliveries by status (or all if null).
     */
    public function count(?string $status = null): int
    {
        if ($status !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM webhook_deliveries WHERE status = :status'
            );
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM webhook_deliveries');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge delivered/failed records older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purge(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            "DELETE FROM webhook_deliveries
             WHERE status IN ('delivered', 'failed') AND created_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $endpointId, string $eventType): array
    {
        $endpointId = trim($endpointId);
        $eventType  = trim($eventType);
        if ($endpointId === '') {
            throw new \InvalidArgumentException('endpoint_id must not be empty.');
        }
        if ($eventType === '') {
            throw new \InvalidArgumentException('event_type must not be empty.');
        }
        return [$endpointId, $eventType];
    }
}
