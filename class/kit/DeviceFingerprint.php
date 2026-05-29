<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DeviceFingerprint — device recognition for fraud detection and trust scoring.
 *
 * Records device signatures (browser fingerprint, user-agent, IP, etc.) seen
 * for each user. A known, trusted device lowers friction; an unknown or
 * suspicious device can trigger step-up authentication.
 *
 * The raw fingerprint string is hashed (SHA-256) before storage so that
 * sensitive signals are not persisted in plain text.
 *
 * ## Usage
 *
 * ```php
 * $df = new DeviceFingerprint($pdo);
 *
 * // Register / update a device seen by a user
 * $id = $df->seen('user-1', 'Mozilla/5.0...', '203.0.113.5', ['screen' => '1920x1080']);
 *
 * // Check trust
 * if ($df->isTrusted('user-1', 'Mozilla/5.0...')) {
 *     // skip extra verification
 * }
 *
 * // Mark a device as blocked (e.g. stolen device)
 * $df->block($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE device_fingerprints (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     fingerprint  VARCHAR(64)  NOT NULL,
 *     user_agent   TEXT         NULL,
 *     ip_address   VARCHAR(45)  NULL,
 *     meta         TEXT         NULL,
 *     trust_level  INTEGER      NOT NULL DEFAULT 0,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'seen',
 *     seen_count   INTEGER      NOT NULL DEFAULT 1,
 *     last_seen_at DATETIME     NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, fingerprint)
 * );
 * ```
 */
final class DeviceFingerprint
{
    public const STATUS_SEEN    = 'seen';
    public const STATUS_TRUSTED = 'trusted';
    public const STATUS_BLOCKED = 'blocked';

    /** Minimum seen_count before a device is auto-trusted via trust(). */
    public const DEFAULT_TRUST_THRESHOLD = 3;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a device observation for a user.
     *
     * If the (user_id, fingerprint-hash) pair already exists the row is
     * updated: seen_count incremented, last_seen_at refreshed, ip / user-agent
     * updated to the latest values.
     *
     * @param array<string,mixed>|null $meta Optional extra signals.
     * @return int Row ID (new or existing).
     * @throws \InvalidArgumentException on empty userId or rawFingerprint.
     */
    public function seen(string $userId, string $rawFingerprint, ?string $ip = null, ?array $meta = null): int
    {
        $userId = trim($userId);
        $rawFingerprint = trim($rawFingerprint);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($rawFingerprint === '') {
            throw new \InvalidArgumentException('fingerprint must not be empty.');
        }

        $hash = hash('sha256', $rawFingerprint);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Cross-driver upsert
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db()->prepare(
                'INSERT INTO device_fingerprints
                     (user_id, fingerprint, ip_address, meta, last_seen_at, created_at)
                 VALUES (:uid, :fp, :ip, :meta, :now, :now2)
                 ON CONFLICT (user_id, fingerprint) DO UPDATE SET
                     ip_address   = excluded.ip_address,
                     meta         = excluded.meta,
                     seen_count   = seen_count + 1,
                     last_seen_at = excluded.last_seen_at'
            );
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO device_fingerprints
                     (user_id, fingerprint, ip_address, meta, last_seen_at, created_at)
                 VALUES (:uid, :fp, :ip, :meta, :now, :now2)
                 ON DUPLICATE KEY UPDATE
                     ip_address   = VALUES(ip_address),
                     meta         = VALUES(meta),
                     seen_count   = seen_count + 1,
                     last_seen_at = VALUES(last_seen_at)'
            );
        }
        $stmt->execute([
            ':uid'  => $userId,
            ':fp'   => $hash,
            ':ip'   => $ip,
            ':meta' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ':now'  => $now,
            ':now2' => $now,
        ]);

        // Fetch the row id after upsert
        $sel = $this->db()->prepare(
            'SELECT id FROM device_fingerprints WHERE user_id = :uid AND fingerprint = :fp'
        );
        $sel->execute([':uid' => $userId, ':fp' => $hash]);
        return (int)$sel->fetchColumn();
    }

    /**
     * Retrieve a device record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM device_fingerprints WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the device record for a user+fingerprint pair.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $userId, string $rawFingerprint): ?array
    {
        $hash = hash('sha256', trim($rawFingerprint));
        $stmt = $this->db()->prepare(
            'SELECT * FROM device_fingerprints WHERE user_id = :uid AND fingerprint = :fp'
        );
        $stmt->execute([':uid' => trim($userId), ':fp' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return true if the device is STATUS_TRUSTED for this user.
     */
    public function isTrusted(string $userId, string $rawFingerprint): bool
    {
        $row = $this->find($userId, $rawFingerprint);
        return $row !== null && $row['status'] === self::STATUS_TRUSTED;
    }

    /**
     * Return true if the device is STATUS_BLOCKED for this user.
     */
    public function isBlocked(string $userId, string $rawFingerprint): bool
    {
        $row = $this->find($userId, $rawFingerprint);
        return $row !== null && $row['status'] === self::STATUS_BLOCKED;
    }

    /**
     * Explicitly trust a device.
     *
     * @return bool True if found and updated.
     */
    public function trust(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE device_fingerprints SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_TRUSTED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Block a device.
     *
     * @return bool True if found and updated.
     */
    public function block(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE device_fingerprints SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_BLOCKED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all device records for a user, most-recently-seen first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM device_fingerprints WHERE user_id = :uid ORDER BY last_seen_at DESC, id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List trusted device records for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function trustedFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM device_fingerprints
             WHERE user_id = :uid AND status = :status
             ORDER BY last_seen_at DESC, id DESC'
        );
        $stmt->execute([':uid' => trim($userId), ':status' => self::STATUS_TRUSTED]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove a device record.
     *
     * @return bool True if found and deleted.
     */
    public function remove(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM device_fingerprints WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete blocked/seen device records last seen before $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM device_fingerprints WHERE status != :trusted AND last_seen_at < :cutoff'
        );
        $stmt->execute([':trusted' => self::STATUS_TRUSTED, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
