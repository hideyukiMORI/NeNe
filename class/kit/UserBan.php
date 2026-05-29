<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * UserBan — ban / unban users with optional expiry and reason tracking.
 *
 * Bans are stored as append-only log entries. Only the most recent active ban
 * is considered when checking `isBanned()`. Unbanning lifts the active ban
 * without deleting the history.
 *
 * ## Usage
 *
 * ```php
 * $ub = new UserBan($pdo);
 *
 * // Permanent ban
 * $ub->ban('user-1', 'Spam', 'admin-1');
 *
 * // Temporary ban (expires in 7 days)
 * $ub->ban('user-1', 'Abuse', 'admin-2', new \DateTimeImmutable('+7 days'));
 *
 * // Check
 * $ub->isBanned('user-1'); // true
 *
 * // Lift the ban
 * $ub->unban('user-1', 'admin-1');
 *
 * // History
 * $ub->history('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_bans (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     reason      TEXT         NOT NULL DEFAULT '',
 *     banned_by   VARCHAR(255) NOT NULL DEFAULT '',
 *     expires_at  DATETIME     DEFAULT NULL,
 *     lifted_at   DATETIME     DEFAULT NULL,
 *     lifted_by   VARCHAR(255) DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserBan
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Ban a user.
     *
     * If the user already has an active (unexpired, not-lifted) ban, the
     * existing ban is lifted first and a new one is recorded.
     *
     * @throws \InvalidArgumentException if user_id is empty.
     * @throws \InvalidArgumentException if expires_at is not in the future.
     */
    public function ban(
        string $userId,
        string $reason = '',
        string $bannedBy = '',
        ?\DateTimeImmutable $expiresAt = null
    ): int {
        $userId = $this->validateUserId($userId);

        if ($expiresAt !== null) {
            $now = new \DateTimeImmutable();
            if ($expiresAt <= $now) {
                throw new \InvalidArgumentException('expires_at must be in the future.');
            }
        }

        // Lift any existing active ban first
        $this->liftActive($userId, $bannedBy);

        $expStr = $expiresAt?->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'INSERT INTO user_bans (user_id, reason, banned_by, expires_at)
             VALUES (:uid, :reason, :by, :exp)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':reason' => $reason,
            ':by'     => $bannedBy,
            ':exp'    => $expStr,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Lift the active ban for a user.
     *
     * @return bool True if an active ban was found and lifted.
     */
    public function unban(string $userId, string $liftedBy = ''): bool
    {
        return $this->liftActive($this->validateUserId($userId), $liftedBy);
    }

    /**
     * Check whether a user is currently banned (active, non-expired, non-lifted ban).
     */
    public function isBanned(string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_bans
             WHERE user_id   = :uid
               AND lifted_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the active ban record for a user, or null if not banned.
     *
     * @return array{id: int, user_id: string, reason: string, banned_by: string, expires_at: string|null, created_at: string}|null
     */
    public function activeBan(string $userId): ?array
    {
        $userId = $this->validateUserId($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, reason, banned_by, expires_at, created_at
             FROM user_bans
             WHERE user_id   = :uid
               AND lifted_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the full ban history for a user (most recent first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, reason, banned_by, expires_at, lifted_at, lifted_by, created_at
             FROM user_bans
             WHERE user_id = :uid
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count total bans (including lifted/expired) for a user.
     */
    public function banCount(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare('SELECT COUNT(*) FROM user_bans WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }

    private function liftActive(string $userId, string $liftedBy): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE user_bans
             SET lifted_at = CURRENT_TIMESTAMP, lifted_by = :by
             WHERE user_id   = :uid
               AND lifted_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':by' => $liftedBy, ':uid' => $userId, ':now' => $now]);
        return $stmt->rowCount() > 0;
    }
}
