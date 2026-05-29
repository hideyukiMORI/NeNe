<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Webhook subscription endpoint management.
 *
 * Manages the registry of webhook subscribers — who should be notified (URL)
 * and for which event types.  Each subscription has an auto-generated HMAC
 * secret used to sign outgoing payloads (see WebhookSigner).  Delivery
 * tracking is handled separately by WebhookDelivery.
 *
 * ## Usage
 *
 * ```php
 * $aw = new ApiWebhook($pdo);
 *
 * // Register a subscriber
 * ['id' => $id, 'secret' => $secret] = $aw->subscribe('order.created', 'https://example.com/hook');
 *
 * // Get active subscribers for an event
 * $hooks = $aw->forEvent('order.created');
 *
 * // Rotate the signing secret
 * $newSecret = $aw->rotateSecret($id);
 *
 * // Enable / disable without deleting
 * $aw->deactivate($id);
 * $aw->activate($id);
 *
 * // Remove permanently
 * $aw->unsubscribe($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE api_webhooks (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     event_type    VARCHAR(100) NOT NULL,
 *     endpoint_url  TEXT         NOT NULL,
 *     secret        VARCHAR(64)  NOT NULL,
 *     active        TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ApiWebhook
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /** Generate a random hex secret (32 bytes = 64 hex chars). */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    /**
     * Register a new webhook subscriber.
     *
     * Returns an array with 'id' (int) and 'secret' (plaintext hex string).
     * Store the secret securely — it is only returned at registration time.
     *
     * @return array{id: int, secret: string}
     * @throws \InvalidArgumentException if eventType or endpointUrl are empty.
     */
    public function subscribe(string $eventType, string $endpointUrl): array
    {
        if ($eventType === '') {
            throw new \InvalidArgumentException('eventType must not be empty.');
        }

        if ($endpointUrl === '') {
            throw new \InvalidArgumentException('endpointUrl must not be empty.');
        }

        $secret = $this->generateSecret();

        $stmt = $this->db()->prepare(
            'INSERT INTO api_webhooks (event_type, endpoint_url, secret)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$eventType, $endpointUrl, $secret]);

        return ['id' => (int)$this->db()->lastInsertId(), 'secret' => $secret];
    }

    // ── unsubscribe ───────────────────────────────────────────────────────────

    /**
     * Permanently remove a webhook subscription.
     */
    public function unsubscribe(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM api_webhooks WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a webhook subscription row (including hashed secret).
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM api_webhooks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── rotateSecret ──────────────────────────────────────────────────────────

    /**
     * Generate and store a new signing secret, returning the new plaintext value.
     *
     * Returns null if the subscription does not exist.
     */
    public function rotateSecret(int $id): ?string
    {
        $secret = $this->generateSecret();
        $stmt   = $this->db()->prepare(
            'UPDATE api_webhooks
             SET secret = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$secret, $id]);
        return $stmt->rowCount() > 0 ? $secret : null;
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    /**
     * Enable a webhook subscription (allows delivery).
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE api_webhooks SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Disable a webhook subscription without deleting it.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE api_webhooks SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return all active subscriptions for a given event type.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forEvent(string $eventType): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM api_webhooks
             WHERE event_type = ? AND active = 1
             ORDER BY id ASC'
        );
        $stmt->execute([$eventType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all subscriptions (active and inactive).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM api_webhooks ORDER BY id ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
