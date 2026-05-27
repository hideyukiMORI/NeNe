<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Admin impersonation session audit log.
 *
 * Records when a privileged user (admin) starts impersonating another user
 * account, and when the impersonation ends.  Provides an immutable audit trail
 * for security reviews and compliance.
 *
 * ## Usage
 *
 * ```php
 * $il = new ImpersonationLog($pdo);
 *
 * // Admin starts impersonating a user
 * $sessionId = $il->start('admin-1', 'user-42', 'Support request #1234');
 *
 * // Admin ends the session
 * $il->end($sessionId);
 *
 * // Check for currently active impersonations
 * $il->active();              // all active (no ended_at)
 * $il->active('admin-1');     // by specific admin
 *
 * // Audit queries
 * $il->history('user-42');    // all sessions where user-42 was target
 * $il->purgeOlderThan('2025-01-01 00:00:00');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE impersonation_log (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     admin_id    VARCHAR(255) NOT NULL,
 *     target_id   VARCHAR(255) NOT NULL,
 *     reason      TEXT         NULL,
 *     started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     ended_at    DATETIME     NULL
 * );
 * ```
 */
final class ImpersonationLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── start ─────────────────────────────────────────────────────────────────

    /**
     * Record the start of an admin impersonation session.
     *
     * @return int New session ID.
     * @throws \InvalidArgumentException if adminId or targetId are empty.
     */
    public function start(string $adminId, string $targetId, ?string $reason = null): int
    {
        if ($adminId === '') {
            throw new \InvalidArgumentException('adminId must not be empty.');
        }

        if ($targetId === '') {
            throw new \InvalidArgumentException('targetId must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO impersonation_log (admin_id, target_id, reason)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$adminId, $targetId, $reason]);
        return (int)$this->db()->lastInsertId();
    }

    // ── end ───────────────────────────────────────────────────────────────────

    /**
     * Record the end of an impersonation session.
     *
     * Returns false if the session does not exist or has already ended.
     */
    public function end(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE impersonation_log
             SET ended_at = CURRENT_TIMESTAMP
             WHERE id = ? AND ended_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a session row by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM impersonation_log WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return currently active (not ended) impersonation sessions.
     * Optionally filter by admin.
     *
     * @return array<int,array<string,mixed>>
     */
    public function active(?string $adminId = null): array
    {
        if ($adminId !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM impersonation_log
                 WHERE ended_at IS NULL AND admin_id = ?
                 ORDER BY started_at DESC'
            );
            $stmt->execute([$adminId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM impersonation_log
                 WHERE ended_at IS NULL
                 ORDER BY started_at DESC'
            );
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all impersonation sessions where a user was the target.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(string $targetId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM impersonation_log
             WHERE target_id = ?
             ORDER BY started_at DESC'
        );
        $stmt->execute([$targetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    /**
     * Delete completed (ended) sessions older than a cutoff datetime.
     *
     * Active sessions (ended_at IS NULL) are never deleted.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $before): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM impersonation_log
             WHERE ended_at IS NOT NULL AND started_at < ?'
        );
        $stmt->execute([$before]);
        return (int)$stmt->rowCount();
    }
}
