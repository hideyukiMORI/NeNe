<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * OnlineStatus — track user presence with heartbeat-based online/idle/offline states.
 *
 * Users update their heartbeat; the status is derived from how long ago the
 * last heartbeat was received:
 * - `online`  → seen within the online threshold (default 60 s)
 * - `idle`    → seen within the idle threshold (default 300 s)
 * - `offline` → not seen within the idle threshold, or never seen
 *
 * ## Usage
 *
 * ```php
 * $os = new OnlineStatus($pdo);
 *
 * // Client sends heartbeat
 * $os->heartbeat('user-1');
 *
 * // Check status
 * $os->status('user-1'); // 'online'|'idle'|'offline'
 *
 * // Explicitly mark offline (on logout / websocket close)
 * $os->setOffline('user-1');
 *
 * // Get all currently online users
 * $os->listOnline();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE online_status (
 *     user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
 *     last_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     is_offline TINYINT(1)   NOT NULL DEFAULT 0
 * );
 * ```
 */
final class OnlineStatus
{
    private const DEFAULT_ONLINE_SECONDS = 60;
    private const DEFAULT_IDLE_SECONDS   = 300;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $onlineSeconds = self::DEFAULT_ONLINE_SECONDS,
        private readonly int $idleSeconds = self::DEFAULT_IDLE_SECONDS
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a heartbeat for a user (marks them as online).
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function heartbeat(string $userId): void
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO online_status (user_id, last_seen, is_offline) VALUES (:uid, CURRENT_TIMESTAMP, 0)
                 ON CONFLICT (user_id) DO UPDATE SET last_seen = CURRENT_TIMESTAMP, is_offline = 0'
            )->execute([':uid' => $userId]);
        } else {
            $db->prepare(
                'INSERT INTO online_status (user_id, last_seen, is_offline) VALUES (:uid, CURRENT_TIMESTAMP, 0)
                 ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP, is_offline = 0'
            )->execute([':uid' => $userId]);
        }
    }

    /**
     * Explicitly mark a user as offline (e.g. on logout or socket close).
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function setOffline(string $userId): void
    {
        $userId = $this->validateUserId($userId);
        $this->db()->prepare(
            'UPDATE online_status SET is_offline = 1 WHERE user_id = :uid'
        )->execute([':uid' => $userId]);
    }

    /**
     * Get the status for a user.
     *
     * @return 'online'|'idle'|'offline'
     */
    public function status(string $userId): string
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT last_seen, is_offline FROM online_status WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || (int)$row['is_offline'] === 1) {
            return 'offline';
        }

        return $this->computeStatus((string)$row['last_seen']);
    }

    /**
     * Get the last seen timestamp for a user.
     *
     * Returns null if the user has never been seen.
     */
    public function lastSeen(string $userId): ?\DateTimeImmutable
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT last_seen FROM online_status WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? new \DateTimeImmutable((string)$val) : null;
    }

    /**
     * Get all users with 'online' status (seen within online threshold, not offline).
     *
     * @return list<string>
     */
    public function listOnline(): array
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$this->onlineSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT user_id FROM online_status
             WHERE last_seen >= :cutoff AND is_offline = 0
             ORDER BY last_seen DESC'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get all users with 'idle' status (seen within idle threshold but not online threshold).
     *
     * @return list<string>
     */
    public function listIdle(): array
    {
        $onlineCutoff = (new \DateTimeImmutable())->modify("-{$this->onlineSeconds} seconds")->format('Y-m-d H:i:s');
        $idleCutoff   = (new \DateTimeImmutable())->modify("-{$this->idleSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt         = $this->db()->prepare(
            'SELECT user_id FROM online_status
             WHERE last_seen < :online_cutoff AND last_seen >= :idle_cutoff AND is_offline = 0
             ORDER BY last_seen DESC'
        );
        $stmt->execute([':online_cutoff' => $onlineCutoff, ':idle_cutoff' => $idleCutoff]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Remove stale records for users who have been offline beyond the idle threshold.
     *
     * @return int Number of rows deleted.
     */
    public function purgeStale(): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$this->idleSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM online_status WHERE last_seen < :cutoff OR is_offline = 1'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
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

    private function computeStatus(string $lastSeen): string
    {
        $now  = new \DateTimeImmutable();
        $seen = new \DateTimeImmutable($lastSeen);
        $diff = $now->getTimestamp() - $seen->getTimestamp();

        if ($diff <= $this->onlineSeconds) {
            return 'online';
        }
        if ($diff <= $this->idleSeconds) {
            return 'idle';
        }
        return 'offline';
    }
}
