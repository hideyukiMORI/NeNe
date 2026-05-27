<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * IpBlocklist — global IP address blocklist with optional expiry.
 *
 * Blocks specific IPv4 or IPv6 addresses (exact match) from accessing the
 * application. Unlike `IpAllowlist` (which is per-resource allowlist),
 * this is a global deny list checked early in the request lifecycle.
 *
 * ## Usage
 *
 * ```php
 * $bl = new IpBlocklist($pdo);
 *
 * // Block an IP (optional expiry and reason)
 * $bl->block('203.0.113.1', 'spam', new \DateTimeImmutable('+7 days'));
 * $bl->block('198.51.100.42', 'brute-force');  // never expires
 *
 * // Check on each request
 * if ($bl->isBlocked($_SERVER['REMOTE_ADDR'])) {
 *     http_response_code(403);
 *     exit;
 * }
 *
 * // Manage
 * $bl->unblock('203.0.113.1');
 * $bl->purgeExpired();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE ip_blocklist (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ip         VARCHAR(45)  NOT NULL UNIQUE,
 *     reason     VARCHAR(255) NOT NULL DEFAULT '',
 *     blocked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     expires_at DATETIME     DEFAULT NULL
 * );
 * ```
 */
final class IpBlocklist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an IP to the blocklist (or update reason/expiry if already blocked).
     *
     * @param string                   $reason    Human-readable reason for the block.
     * @param \DateTimeImmutable|null  $expiresAt Expiry time; null = never expires.
     * @return int The blocklist record ID.
     * @throws \InvalidArgumentException if the IP is empty.
     */
    public function block(string $ip, string $reason = '', ?\DateTimeImmutable $expiresAt = null): int
    {
        $ip        = $this->validateIp($ip);
        $expireStr = $expiresAt?->format('Y-m-d H:i:s');
        $db        = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO ip_blocklist (ip, reason, expires_at)
                 VALUES (:ip, :reason, :exp)
                 ON CONFLICT (ip)
                 DO UPDATE SET reason     = excluded.reason,
                               expires_at = excluded.expires_at,
                               blocked_at = CURRENT_TIMESTAMP'
            )->execute([':ip' => $ip, ':reason' => $reason, ':exp' => $expireStr]);
        } else {
            $db->prepare(
                'INSERT INTO ip_blocklist (ip, reason, expires_at)
                 VALUES (:ip, :reason, :exp)
                 ON DUPLICATE KEY UPDATE reason     = VALUES(reason),
                                         expires_at = VALUES(expires_at),
                                         blocked_at = CURRENT_TIMESTAMP'
            )->execute([':ip' => $ip, ':reason' => $reason, ':exp' => $expireStr]);
        }

        $stmt = $db->prepare('SELECT id FROM ip_blocklist WHERE ip = :ip LIMIT 1');
        $stmt->execute([':ip' => $ip]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove an IP from the blocklist.
     *
     * @return bool True if the IP was blocked and is now removed.
     */
    public function unblock(string $ip): bool
    {
        $ip   = $this->validateIp($ip);
        $stmt = $this->db()->prepare('DELETE FROM ip_blocklist WHERE ip = :ip');
        $stmt->execute([':ip' => $ip]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether an IP is currently blocked (considers expiry).
     */
    public function isBlocked(string $ip): bool
    {
        $ip  = trim($ip);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM ip_blocklist
             WHERE ip = :ip AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':ip' => $ip, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Find the blocklist record for an IP.
     *
     * @return array<string,mixed>|null null if not found or expired.
     */
    public function find(string $ip): ?array
    {
        $ip   = trim($ip);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, ip, reason, blocked_at, expires_at
             FROM ip_blocklist
             WHERE ip = :ip AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1'
        );
        $stmt->execute([':ip' => $ip, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all active (non-expired) blocked IPs.
     *
     * @return list<array{id: int, ip: string, reason: string, blocked_at: string, expires_at: string|null}>
     */
    public function all(): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, ip, reason, blocked_at, expires_at
             FROM ip_blocklist
             WHERE expires_at IS NULL OR expires_at > :now
             ORDER BY blocked_at DESC'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all expired blocklist entries.
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'DELETE FROM ip_blocklist WHERE expires_at IS NOT NULL AND expires_at <= :now'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    /**
     * Count active (non-expired) blocked IPs.
     */
    public function count(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM ip_blocklist
             WHERE expires_at IS NULL OR expires_at > :now'
        );
        $stmt->execute([':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            throw new \InvalidArgumentException('ip must not be empty.');
        }
        return $ip;
    }
}
