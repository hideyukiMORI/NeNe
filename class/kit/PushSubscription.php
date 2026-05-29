<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PushSubscription — Web Push notification subscription registry.
 *
 * Stores browser push subscription data (endpoint + keys) per user and device.
 * A subscription is uniquely identified by its endpoint URL. When a browser
 * re-subscribes it sends the same endpoint, so saves are idempotent.
 *
 * This class only handles storage; actual push delivery is the caller's
 * responsibility (e.g. via a web-push library or external API).
 *
 * ## Usage
 *
 * ```php
 * $ps = new PushSubscription($pdo);
 *
 * // Save subscription after navigator.serviceWorker.subscribe()
 * $ps->save('user-1', $endpoint, $p256dhKey, $authKey, 'Chrome/desktop');
 *
 * // Retrieve subscriptions to deliver a notification
 * foreach ($ps->forUser('user-1') as $sub) {
 *     // send push via $sub['endpoint'], $sub['p256dh'], $sub['auth']
 * }
 *
 * // Remove when browser fires pushsubscriptionchange or push fails with 410
 * $ps->remove($endpoint);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE push_subscriptions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     endpoint    TEXT         NOT NULL UNIQUE,
 *     p256dh      TEXT         NOT NULL DEFAULT '',
 *     auth        TEXT         NOT NULL DEFAULT '',
 *     device_hint VARCHAR(100) NOT NULL DEFAULT '',
 *     subscribed_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PushSubscription
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save (create or update) a push subscription.
     *
     * Idempotent: if the endpoint already exists, the keys and device hint
     * are updated to their new values.
     *
     * @param  string $userId      The authenticated user.
     * @param  string $endpoint    Push service endpoint URL.
     * @param  string $p256dh      Client public key (base64url-encoded).
     * @param  string $auth        Auth secret (base64url-encoded).
     * @param  string $deviceHint  Optional human-readable device label.
     * @throws \InvalidArgumentException if user_id or endpoint is empty.
     */
    public function save(
        string $userId,
        string $endpoint,
        string $p256dh = '',
        string $auth = '',
        string $deviceHint = ''
    ): void {
        $userId   = $this->validateUserId($userId);
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new \InvalidArgumentException('endpoint must not be empty.');
        }

        $db = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, device_hint)
                 VALUES (:uid, :ep, :p256dh, :auth, :hint)
                 ON CONFLICT (endpoint)
                 DO UPDATE SET user_id     = excluded.user_id,
                               p256dh      = excluded.p256dh,
                               auth        = excluded.auth,
                               device_hint = excluded.device_hint,
                               subscribed_at = CURRENT_TIMESTAMP'
            )->execute([':uid' => $userId, ':ep' => $endpoint, ':p256dh' => $p256dh, ':auth' => $auth, ':hint' => $deviceHint]);
        } else {
            $db->prepare(
                'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, device_hint)
                 VALUES (:uid, :ep, :p256dh, :auth, :hint)
                 ON DUPLICATE KEY UPDATE user_id     = VALUES(user_id),
                                         p256dh      = VALUES(p256dh),
                                         auth        = VALUES(auth),
                                         device_hint = VALUES(device_hint),
                                         subscribed_at = CURRENT_TIMESTAMP'
            )->execute([':uid' => $userId, ':ep' => $endpoint, ':p256dh' => $p256dh, ':auth' => $auth, ':hint' => $deviceHint]);
        }
    }

    /**
     * Get all push subscriptions for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, endpoint, p256dh, auth, device_hint, subscribed_at
             FROM push_subscriptions WHERE user_id = :uid ORDER BY id ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Find a subscription by endpoint URL.
     *
     * @return array<string,mixed>|null
     */
    public function findByEndpoint(string $endpoint): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, endpoint, p256dh, auth, device_hint, subscribed_at
             FROM push_subscriptions WHERE endpoint = :ep LIMIT 1'
        );
        $stmt->execute([':ep' => trim($endpoint)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Remove a subscription by endpoint URL (call on 410 Gone or user opt-out).
     *
     * @return bool True if the subscription existed and was removed.
     */
    public function remove(string $endpoint): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM push_subscriptions WHERE endpoint = :ep'
        );
        $stmt->execute([':ep' => trim($endpoint)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove all push subscriptions for a user.
     *
     * @return int Number of rows deleted.
     */
    public function removeAllForUser(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'DELETE FROM push_subscriptions WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Count subscriptions for a user.
     */
    public function countForUser(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :uid'
        );
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
}
