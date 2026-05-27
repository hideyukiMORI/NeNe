<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EventLog — append-only domain event store.
 *
 * Records business events (user_registered, order_placed, payment_failed, …)
 * with a subject, optional aggregate context, and arbitrary JSON data.
 * All entries are immutable once written.
 *
 * Intended for event sourcing lite, audit trails, and analytics pipelines.
 *
 * ## Usage
 *
 * ```php
 * $el = new EventLog($pdo);
 *
 * // Record an event
 * $el->record('user_registered', 'user', 'u-1', ['email' => 'a@b.com']);
 *
 * // Replay events for an aggregate
 * $el->forAggregate('user', 'u-1');
 *
 * // Recent events of a type
 * $el->forEvent('user_registered', 10);
 *
 * // Cursor-based paging (for large replays)
 * $el->since($lastSeenId, 100);
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
 *     data           TEXT         NOT NULL DEFAULT '{}',
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
     * Record a domain event.
     *
     * @param  array<string,mixed> $data Arbitrary event payload.
     * @return int The new event ID.
     * @throws \InvalidArgumentException if event_type is empty.
     */
    public function record(
        string $eventType,
        string $aggregateType = '',
        string $aggregateId = '',
        array $data = []
    ): int {
        $eventType = $this->validateEventType($eventType);
        $db        = $this->db();

        $db->prepare(
            'INSERT INTO event_log (event_type, aggregate_type, aggregate_id, data)
             VALUES (:type, :agg_type, :agg_id, :data)'
        )->execute([
            ':type'     => $eventType,
            ':agg_type' => $aggregateType,
            ':agg_id'   => $aggregateId,
            ':data'     => json_encode($data, JSON_THROW_ON_ERROR),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Get all events for an aggregate (ordered by id ASC for replay).
     *
     * @return list<array<string,mixed>>
     */
    public function forAggregate(string $aggregateType, string $aggregateId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, event_type, aggregate_type, aggregate_id, data, occurred_at
             FROM event_log
             WHERE aggregate_type = :agg_type AND aggregate_id = :agg_id
             ORDER BY id ASC'
        );
        $stmt->execute([':agg_type' => $aggregateType, ':agg_id' => $aggregateId]);
        return $this->decodeAll($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get recent events of a specific type.
     *
     * @return list<array<string,mixed>>
     */
    public function forEvent(string $eventType, int $limit = 20): array
    {
        $eventType = $this->validateEventType($eventType);
        $limit     = max(1, $limit);
        $stmt      = $this->db()->prepare(
            "SELECT id, event_type, aggregate_type, aggregate_id, data, occurred_at
             FROM event_log
             WHERE event_type = :type
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':type' => $eventType]);
        return $this->decodeAll($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get all events after a given ID (cursor-based paging for replays).
     *
     * @return list<array<string,mixed>>
     */
    public function since(int $afterId, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, event_type, aggregate_type, aggregate_id, data, occurred_at
             FROM event_log
             WHERE id > :after
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':after' => $afterId]);
        return $this->decodeAll($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get the most recent N events across all types.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, event_type, aggregate_type, aggregate_id, data, occurred_at
             FROM event_log
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $this->decodeAll($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Count all events, optionally filtered by event type.
     */
    public function count(?string $eventType = null): int
    {
        if ($eventType !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM event_log WHERE event_type = :type'
            );
            $stmt->execute([':type' => $eventType]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM event_log');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count events per type (event_type => count mapping).
     *
     * @return array<string,int>
     */
    public function countByType(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT event_type, COUNT(*) AS cnt FROM event_log GROUP BY event_type ORDER BY cnt DESC'
        );
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['event_type']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Purge events older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM event_log WHERE occurred_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateEventType(string $eventType): string
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new \InvalidArgumentException('event_type must not be empty.');
        }
        return $eventType;
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decodeAll(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                $row['data'] = json_decode((string)$row['data'], true) ?? [];
                return $row;
            },
            $rows
        );
    }
}
