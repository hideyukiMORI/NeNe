<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DeviceToken — push notification device token management per user.
 *
 * Stores FCM / APNs / WebPush device tokens for users. A user may have
 * multiple tokens (one per device). Tokens can be deactivated when a push
 * fails or when a device is unregistered. The platform field distinguishes
 * token format and routing.
 *
 * ## Usage
 *
 * ```php
 * $dt = new DeviceToken($pdo);
 *
 * // Register
 * $id = $dt->register('user-42', 'fcm:abc123', DeviceToken::PLATFORM_ANDROID);
 *
 * // Deactivate after push failure
 * $dt->deactivate($id);
 *
 * // Query
 * $tokens  = $dt->activeFor('user-42');
 * $all     = $dt->forUser('user-42');
 * $found   = $dt->findByToken('fcm:abc123');
 *
 * // Cleanup
 * $dt->deleteInactive('user-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE device_tokens (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     token       TEXT         NOT NULL,
 *     platform    VARCHAR(20)  NOT NULL DEFAULT 'unknown',
 *     is_active   TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DeviceToken
{
    public const PLATFORM_IOS     = 'ios';
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_WEB     = 'web';
    public const PLATFORM_UNKNOWN = 'unknown';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register a device token for a user.
     *
     * If the token already exists for this user (any status), it is reactivated
     * and the platform is updated. Otherwise a new row is inserted.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty userId or token.
     */
    public function register(string $userId, string $token, string $platform = self::PLATFORM_UNKNOWN): int
    {
        $userId = trim($userId);
        $token  = trim($token);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }
        if ($token === '') {
            throw new \InvalidArgumentException('token must not be empty.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Check for existing token for this user
        $stmt = $this->db()->prepare(
            'SELECT id FROM device_tokens WHERE user_id = :uid AND token = :token'
        );
        $stmt->execute([':uid' => $userId, ':token' => $token]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            // Reactivate
            $stmt2 = $this->db()->prepare(
                'UPDATE device_tokens SET is_active = 1, platform = :platform, updated_at = :now
                 WHERE id = :id'
            );
            $stmt2->execute([':platform' => $platform, ':now' => $now, ':id' => (int)$existing]);
            return (int)$existing;
        }

        $stmt3 = $this->db()->prepare(
            'INSERT INTO device_tokens (user_id, token, platform, is_active, created_at, updated_at)
             VALUES (:uid, :token, :platform, 1, :now, :now)'
        );
        $stmt3->execute([
            ':uid'      => $userId,
            ':token'    => $token,
            ':platform' => $platform,
            ':now'      => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single device token record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM device_tokens WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a record by token string (regardless of user).
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM device_tokens WHERE token = :token');
        $stmt->execute([':token' => trim($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Deactivate a device token.
     *
     * @return bool True if found and updated.
     */
    public function deactivate(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE device_tokens SET is_active = 0, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a device token record permanently.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM device_tokens WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List active tokens for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM device_tokens
             WHERE user_id = :uid AND is_active = 1
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all tokens for a user (active and inactive).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM device_tokens WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all inactive tokens for a user.
     *
     * @return int Number of rows deleted.
     */
    public function deleteInactive(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM device_tokens WHERE user_id = :uid AND is_active = 0'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    /**
     * Count active tokens for a user.
     */
    public function countActive(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM device_tokens WHERE user_id = :uid AND is_active = 1'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
