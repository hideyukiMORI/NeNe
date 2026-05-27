<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EventLog — append-only domain event log for event sourcing–lite patterns.
 *
 * Records structured domain events (e.g. OrderPlaced, UserRegistered,
 * PaymentFailed) against aggregates. Each event has a type, aggregate
 * type and ID, an optional actor, and a JSON payload. The log is
 * append-only: events are never updated or deleted in normal operation.
 *
 * This is lighter than full event sourcing — it does not replay events
 * to reconstruct state, but provides a durable, queryable event history.
 *
 * ## Usage
 *
 * ```php
 * $el = new EventLog($pdo);
 *
 * // Append events
 * $el->append('OrderPlaced',  'order', '99', 'user-1', ['total' => 9900]);
 * $el->append('PaymentFailed', 'order', '99', 'system', ['reason' => 'card_declined']);
 *
 * // Query
 * $history = $el->forAggregate('order', '99');
 * $recent  = $el->ofType('OrderPlaced', 20);
 * $byActor = $el->byActor('user-1', 50);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE event_log (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     event_type     VARCHAR(100) NOT NULL,
 *     aggregate_type VARCHAR(100) NOT NULL DEFAULT '',
 *     aggregate_id   VARCHAR(255) NOT NULL DEFAULT '',
 *     actor_id       VARCHAR(255) NOT NULL DEFAULT '',
 *     payload        TEXT         NOT NULL DEFAULT '{}',
 *     occurred_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class EventLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Append a domain event.
     *
     * @param  array<string,mixed> $payload  Arbitrary event data (will be JSON-encoded).
     * @return int Row ID of the new event.
     * @throws \InvalidArgumentException on empty event_type.
     */
    public function append(
        string $eventType,
        string $aggregateType = '',
        string $aggregateId = '',
        string $actorId = '',
        array $payload = []
    ): int {
        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new \InvalidArgumentException('event_type must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO event_log (event_type, aggregate_type, aggregate_id, actor_id, payload)
             VALUES (:etype, :atype, :aid, :actor, :payload)'
        );
        $stmt->execute([
            ':etype'   => $eventType,
            ':atype'   => trim($aggregateType),
            ':aid'     => trim($aggregateId),
            ':actor'   => trim($actorId),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single event by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM event_log WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return all events for a given aggregate (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forAggregate(string $aggregateType, string $aggregateId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, event_type, aggregate_type, aggregate_id, actor_id, payload, occurred_at
             FROM event_log
             WHERE aggregate_type = :atype AND aggregate_id = :aid
             ORDER BY id ASC'
        );
        $stmt->execute([':atype' => trim($aggregateType), ':aid' => trim($aggregateId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return recent events of a given type (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function ofType(string $eventType, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, event_type, aggregate_type, aggregate_id, actor_id, payload, occurred_at
             FROM event_log
             WHERE event_type = :etype
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':etype', trim($eventType));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return recent events by a given actor (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function byActor(string $actorId, int $limit = 50): array
    {
        $actorId = trim($actorId);
        if ($actorId === '') {
            throw new \InvalidArgumentException('actor_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, event_type, aggregate_type, aggregate_id, actor_id, payload, occurred_at
             FROM event_log
             WHERE actor_id = :actor
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':actor', $actorId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the most recent events across all types (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, event_type, aggregate_type, aggregate_id, actor_id, payload, occurred_at
             FROM event_log
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count events by type.
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT event_type, COUNT(*) AS cnt FROM event_log GROUP BY event_type ORDER BY cnt DESC'
        );
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['event_type']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Delete events older than a given number of days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM event_log WHERE occurred_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
