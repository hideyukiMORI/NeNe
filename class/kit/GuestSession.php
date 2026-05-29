<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * GuestSession — anonymous visitor session tracking with key-value data bag.
 *
 * Issues opaque session tokens for unauthenticated visitors. Each session
 * has a TTL and can carry arbitrary key-value data (e.g. cart contents,
 * wizard state, A/B test variant). Useful when you need per-visitor state
 * before the user logs in.
 *
 * ## Usage
 *
 * ```php
 * $gs = new GuestSession($pdo);
 *
 * // Start a guest session
 * $token = $gs->start(ttlSeconds: 1800);
 *
 * // Store and retrieve data
 * $gs->set($token, 'cart_id', 'cart-42');
 * $gs->set($token, 'variant', 'B');
 * $value = $gs->get($token, 'cart_id'); // 'cart-42'
 *
 * // Promote to user session on login
 * $gs->linkUser($token, 'user-1');
 *
 * // Keep alive
 * $gs->touch($token, 1800);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE guest_sessions (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     token      VARCHAR(64)  NOT NULL UNIQUE,
 *     user_id    VARCHAR(255) NULL,
 *     data       TEXT         NOT NULL DEFAULT '{}',
 *     expires_at DATETIME     NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class GuestSession
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Start a new guest session.
     *
     * @param  int $ttlSeconds Session lifetime in seconds (default 1800 = 30 min).
     * @return string 32-char hex token.
     */
    public function start(int $ttlSeconds = 1800): string
    {
        $token     = bin2hex(random_bytes(16));
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO guest_sessions (token, expires_at) VALUES (:tok, :exp)'
        )->execute([':tok' => $token, ':exp' => $expiresAt]);

        return $token;
    }

    /**
     * Load a session (returns null if missing or expired).
     *
     * @return array<string,mixed>|null
     */
    public function load(string $token): ?array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, token, user_id, data, expires_at, created_at
             FROM guest_sessions WHERE token = :tok AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([':tok' => trim($token), ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Set a key-value pair in the session data bag.
     *
     * @param mixed $value  Scalar or JSON-serializable value.
     * @return bool True if the session was found and updated.
     */
    public function set(string $token, string $key, mixed $value): bool
    {
        $row = $this->load($token);
        if ($row === null) {
            return false;
        }
        $data      = json_decode((string)$row['data'], true) ?? [];
        $data[$key] = $value;
        $stmt       = $this->db()->prepare(
            'UPDATE guest_sessions SET data = :data WHERE token = :tok'
        );
        $stmt->execute([':data' => json_encode($data, JSON_UNESCAPED_UNICODE), ':tok' => trim($token)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a value from the session data bag.
     *
     * Returns $default if the session is missing/expired or the key doesn't exist.
     *
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function get(string $token, string $key, mixed $default = null): mixed
    {
        $row = $this->load($token);
        if ($row === null) {
            return $default;
        }
        $data = json_decode((string)$row['data'], true) ?? [];
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Remove a key from the session data bag.
     *
     * @return bool True if the session existed and was updated.
     */
    public function remove(string $token, string $key): bool
    {
        $row = $this->load($token);
        if ($row === null) {
            return false;
        }
        $data = json_decode((string)$row['data'], true) ?? [];
        unset($data[$key]);
        $stmt = $this->db()->prepare(
            'UPDATE guest_sessions SET data = :data WHERE token = :tok'
        );
        $stmt->execute([':data' => json_encode($data, JSON_UNESCAPED_UNICODE), ':tok' => trim($token)]);
        return true;
    }

    /**
     * Associate a logged-in user ID with the guest session (promotion on login).
     *
     * @return bool True if the session existed and was updated.
     */
    public function linkUser(string $token, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE guest_sessions SET user_id = :uid WHERE token = :tok'
        );
        $stmt->execute([':uid' => trim($userId), ':tok' => trim($token)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Extend the session TTL.
     *
     * @return bool True if the session was found and refreshed.
     */
    public function touch(string $token, int $ttlSeconds = 1800): bool
    {
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt      = $this->db()->prepare(
            'UPDATE guest_sessions SET expires_at = :exp WHERE token = :tok AND expires_at > :now'
        );
        $stmt->execute([':exp' => $expiresAt, ':tok' => trim($token), ':now' => $now]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Destroy a guest session.
     *
     * @return bool True if it existed and was deleted.
     */
    public function destroy(string $token): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM guest_sessions WHERE token = :tok');
        $stmt->execute([':tok' => trim($token)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all expired sessions (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM guest_sessions WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
