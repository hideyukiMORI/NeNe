<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ServiceAccount — machine-to-machine auth credentials with key rotation.
 *
 * Issues named service accounts with a client ID and secret. The secret is
 * stored as a SHA-256 hash; the raw value is returned once at creation and
 * once on rotation. Supports enable/disable to temporarily revoke access.
 *
 * ## Usage
 *
 * ```php
 * $sa = new ServiceAccount($pdo);
 *
 * // Create account
 * [$clientId, $secret] = $sa->create('data-pipeline', ['read:events', 'write:metrics']);
 *
 * // Verify credentials (bearer auth or basic auth)
 * $account = $sa->verify($clientId, $secret);
 * if ($account === null) { /* invalid or disabled *\/ }
 *
 * // Rotate secret
 * $newSecret = $sa->rotate($clientId);
 *
 * // Manage
 * $sa->disable($clientId);
 * $sa->enable($clientId);
 * $sa->delete($clientId);
 * $sa->all();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE service_accounts (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     client_id   VARCHAR(64)  NOT NULL UNIQUE,
 *     secret_hash VARCHAR(64)  NOT NULL,
 *     name        VARCHAR(100) NOT NULL,
 *     scopes      TEXT         NOT NULL DEFAULT '',
 *     enabled     TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     rotated_at  DATETIME     NULL
 * );
 * ```
 */
final class ServiceAccount
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new service account.
     *
     * @param  string   $name    Human-readable name.
     * @param  string[] $scopes  Granted scopes.
     * @return array{string, string}  [clientId, rawSecret]
     * @throws \InvalidArgumentException if name is empty.
     */
    public function create(string $name, array $scopes = []): array
    {
        $name = $this->validateName($name);

        $clientId  = 'sa_' . bin2hex(random_bytes(12)); // 27-char prefixed ID
        $rawSecret = bin2hex(random_bytes(32));           // 64-char hex secret

        $this->db()->prepare(
            'INSERT INTO service_accounts (client_id, secret_hash, name, scopes)
             VALUES (:cid, :hash, :name, :scopes)'
        )->execute([
            ':cid'    => $clientId,
            ':hash'   => hash('sha256', $rawSecret),
            ':name'   => $name,
            ':scopes' => implode(' ', $scopes),
        ]);

        return [$clientId, $rawSecret];
    }

    /**
     * Verify a client ID + secret pair. Returns the account record, or null if invalid/disabled.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $clientId, string $rawSecret): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, client_id, name, scopes, enabled, created_at, rotated_at
             FROM service_accounts
             WHERE client_id = :cid AND secret_hash = :hash AND enabled = 1
             LIMIT 1'
        );
        $stmt->execute([':cid' => $clientId, ':hash' => hash('sha256', $rawSecret)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Rotate the secret for an existing service account.
     *
     * Returns the new raw secret, or null if the account does not exist.
     */
    public function rotate(string $clientId): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT id FROM service_accounts WHERE client_id = :cid LIMIT 1'
        );
        $stmt->execute([':cid' => $clientId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        $rawSecret = bin2hex(random_bytes(32));
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'UPDATE service_accounts SET secret_hash = :hash, rotated_at = :now WHERE client_id = :cid'
        )->execute([':hash' => hash('sha256', $rawSecret), ':now' => $now, ':cid' => $clientId]);

        return $rawSecret;
    }

    /**
     * Enable a service account.
     */
    public function enable(string $clientId): bool
    {
        return $this->setEnabled($clientId, true);
    }

    /**
     * Disable a service account (credentials stop working immediately).
     */
    public function disable(string $clientId): bool
    {
        return $this->setEnabled($clientId, false);
    }

    /**
     * Find a service account by client ID (secret NOT included in result).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $clientId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, client_id, name, scopes, enabled, created_at, rotated_at
             FROM service_accounts WHERE client_id = :cid LIMIT 1'
        );
        $stmt->execute([':cid' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all service accounts (secrets NOT included).
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, client_id, name, scopes, enabled, created_at, rotated_at
             FROM service_accounts ORDER BY name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a service account permanently.
     *
     * @return bool True if the account existed and was deleted.
     */
    public function delete(string $clientId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM service_accounts WHERE client_id = :cid'
        );
        $stmt->execute([':cid' => $clientId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Service account name must not be empty.');
        }
        return $name;
    }

    private function setEnabled(string $clientId, bool $enabled): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE service_accounts SET enabled = :enabled WHERE client_id = :cid'
        );
        $stmt->execute([':enabled' => $enabled ? 1 : 0, ':cid' => $clientId]);
        return $stmt->rowCount() > 0;
    }
}
