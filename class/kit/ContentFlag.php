<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * User content flagging and moderation queue.
 *
 * Users can flag content (posts, comments, profiles) for review.
 * Moderators then take an action: `approve` (keep), `remove` (take down), or `dismiss` (no action).
 *
 * Duplicate flags by the same user on the same entity are silently ignored.
 *
 * ## Usage
 *
 * ```php
 * $cf = new ContentFlag($pdo);
 *
 * // User flags a post
 * $cf->flag('user-1', 'post', '99', reason: 'spam');
 *
 * // Moderator queue
 * $queue = $cf->queue(status: 'pending');
 *
 * // Moderate
 * $cf->moderate('post', '99', action: 'remove', moderatorId: 'mod-1', note: 'Confirmed spam');
 *
 * // Check moderation status
 * $cf->status('post', '99'); // 'pending'|'approved'|'removed'|'dismissed'|null
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_flags (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reporter_id  VARCHAR(255) NOT NULL,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     reason       VARCHAR(100) NOT NULL DEFAULT '',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     moderator_id VARCHAR(255) DEFAULT NULL,
 *     note         TEXT         NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     resolved_at  DATETIME     DEFAULT NULL,
 *     UNIQUE (reporter_id, entity_type, entity_id)
 * );
 * ```
 */
final class ContentFlag
{
    private const VALID_ACTIONS = ['approve', 'remove', 'dismiss'];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Flag a piece of content for moderation review.
     *
     * Idempotent: a user can only flag the same entity once (UNIQUE reporter+entity).
     *
     * @return bool True if a new flag was created; false if already flagged.
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     */
    public function flag(
        string $reporterId,
        string $entityType,
        string $entityId,
        string $reason = ''
    ): bool {
        $entityType = $this->normaliseStr($entityType, 'entity_type');
        $entityId   = $this->normaliseStr($entityId, 'entity_id');

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO content_flags (reporter_id, entity_type, entity_id, reason)
               VALUES (:reporter, :type, :entity, :reason)'
            : 'INSERT IGNORE INTO content_flags (reporter_id, entity_type, entity_id, reason)
               VALUES (:reporter, :type, :entity, :reason)';

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':reporter' => $reporterId,
            ':type'     => $entityType,
            ':entity'   => $entityId,
            ':reason'   => trim($reason),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resolve a flag (or all flags on the same entity) with a moderation action.
     *
     * All `pending` flags for the entity are updated atomically.
     *
     * @param string $action      'approve' | 'remove' | 'dismiss'
     * @param string $moderatorId Moderator's user ID.
     * @param string $note        Optional moderation note.
     * @return int Number of flag rows updated.
     * @throws \InvalidArgumentException if action is invalid.
     */
    public function moderate(
        string $entityType,
        string $entityId,
        string $action,
        string $moderatorId,
        string $note = ''
    ): int {
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid action '{$action}'. Must be one of: " . implode(', ', self::VALID_ACTIONS) . '.'
            );
        }

        $stmt = $this->db()->prepare(
            "UPDATE content_flags
             SET status = :action, moderator_id = :mod, note = :note,
                 resolved_at = CURRENT_TIMESTAMP
             WHERE entity_type = :type AND entity_id = :entity AND status = 'pending'"
        );
        $stmt->execute([
            ':action' => $action,
            ':mod'    => $moderatorId,
            ':note'   => trim($note),
            ':type'   => $entityType,
            ':entity' => $entityId,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Get the current moderation status for an entity.
     *
     * Returns the most recent resolution action, or 'pending' if unresolved, or null if never flagged.
     *
     * @return 'pending'|'approve'|'remove'|'dismiss'|null
     */
    public function status(string $entityType, string $entityId): ?string
    {
        // Prefer resolved status; fall back to pending
        $stmt = $this->db()->prepare(
            'SELECT status FROM content_flags
             WHERE entity_type = :type AND entity_id = :entity
             ORDER BY CASE status WHEN \'pending\' THEN 1 ELSE 0 END ASC, resolved_at DESC
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':entity' => $entityId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Return the moderation queue filtered by status.
     *
     * Groups by entity so each entity appears once even if flagged multiple times.
     *
     * @param  string $status 'pending' | 'approve' | 'remove' | 'dismiss'
     * @param  int    $limit  Max rows (1–100).
     * @return list<array<string, mixed>>
     */
    public function queue(string $status = 'pending', int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt  = $this->db()->prepare(
            'SELECT entity_type, entity_id, status,
                    COUNT(*) AS flag_count,
                    MIN(created_at) AS first_flagged_at
             FROM content_flags
             WHERE status = :status
             GROUP BY entity_type, entity_id, status
             ORDER BY first_flagged_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List all flags submitted by a specific reporter.
     *
     * @return list<array<string, mixed>>
     */
    public function byReporter(string $reporterId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM content_flags WHERE reporter_id = :reporter ORDER BY created_at DESC'
        );
        $stmt->execute([':reporter' => $reporterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function normaliseStr(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }
        return $value;
    }
}
