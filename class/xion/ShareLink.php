<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ShareLink — shareable links with optional password and expiry.
 *
 * Generates short tokens pointing to arbitrary resources (entity_type +
 * entity_id). Supports optional password protection, view-count limits, and
 * TTL expiry. The token is returned once; the recipient resolves it to the
 * target resource.
 *
 * ## Usage
 *
 * ```php
 * $sl = new ShareLink($pdo);
 *
 * // Create a link valid for 24h, max 10 views
 * $token = $sl->create('post', '42', 'user-1', maxViews: 10, ttlSeconds: 86400);
 *
 * // Resolve (increments view count)
 * $link = $sl->resolve($token);
 * if ($link === null) { // expired, exhausted, or not found }
 *
 * // Password-protected
 * $token2 = $sl->create('doc', '7', 'user-1', password: 'secret');
 * $link2  = $sl->resolve($token2, 'secret');
 *
 * // Revoke
 * $sl->revoke($token);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE share_links (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     token         VARCHAR(64)  NOT NULL UNIQUE,
 *     target_type   VARCHAR(100) NOT NULL,
 *     target_id     VARCHAR(255) NOT NULL,
 *     owner_id      VARCHAR(255) NOT NULL DEFAULT '',
 *     label         VARCHAR(255) NOT NULL DEFAULT '',
 *     password_hash VARCHAR(64)  NULL,
 *     views         INTEGER      NOT NULL DEFAULT 0,
 *     max_views     INTEGER      NOT NULL DEFAULT 0,
 *     expires_at    DATETIME     NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ShareLink
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new share link.
     *
     * @param  int    $maxViews   Maximum allowed views (0 = unlimited).
     * @param  int    $ttlSeconds Seconds until expiry (0 = no expiry).
     * @param  string $password   Optional plaintext password (stored as SHA-256 hash).
     * @return string 32-char hex token.
     * @throws \InvalidArgumentException on empty targetType/targetId.
     */
    public function create(
        string $targetType,
        string $targetId,
        string $ownerId = '',
        string $label = '',
        int $maxViews = 0,
        int $ttlSeconds = 0,
        string $password = ''
    ): string {
        $targetType = $this->validateNonEmpty($targetType, 'target_type');
        $targetId   = $this->validateNonEmpty($targetId, 'target_id');
        $token      = bin2hex(random_bytes(16)); // 32-char hex token
        $expiresAt  = $ttlSeconds > 0
            ? (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s')
            : null;
        $passHash   = $password !== '' ? hash('sha256', $password) : null;

        $this->db()->prepare(
            'INSERT INTO share_links
                (token, target_type, target_id, owner_id, label, password_hash, max_views, expires_at)
             VALUES (:tok, :type, :id, :owner, :label, :pass, :maxv, :exp)'
        )->execute([
            ':tok'   => $token,
            ':type'  => $targetType,
            ':id'    => $targetId,
            ':owner' => $ownerId,
            ':label' => $label,
            ':pass'  => $passHash,
            ':maxv'  => $maxViews,
            ':exp'   => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Resolve a token to its target resource (and increment view count).
     *
     * Returns null if:
     * - Token not found
     * - Link is expired
     * - max_views > 0 and views >= max_views
     * - Password required and wrong password provided
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $token, string $password = ''): ?array
    {
        $token = trim($token);
        $stmt  = $this->db()->prepare(
            'SELECT id, token, target_type, target_id, owner_id, label,
                    password_hash, views, max_views, expires_at, created_at
             FROM share_links WHERE token = :tok LIMIT 1'
        );
        $stmt->execute([':tok' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Expiry check
        if ($row['expires_at'] !== null && $row['expires_at'] <= $now) {
            return null;
        }

        // View limit check
        if ((int)$row['max_views'] > 0 && (int)$row['views'] >= (int)$row['max_views']) {
            return null;
        }

        // Password check
        if ($row['password_hash'] !== null) {
            if (hash('sha256', $password) !== $row['password_hash']) {
                return null;
            }
        }

        // Increment view count
        $this->db()->prepare(
            'UPDATE share_links SET views = views + 1 WHERE token = :tok'
        )->execute([':tok' => $token]);

        // Return record without password_hash
        unset($row['password_hash']);
        return $row;
    }

    /**
     * Look up a link's metadata without incrementing views or checking password.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $token): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, token, target_type, target_id, owner_id, label,
                    views, max_views, expires_at, created_at
             FROM share_links WHERE token = :tok LIMIT 1'
        );
        $stmt->execute([':tok' => trim($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Revoke a share link (deletes the row).
     *
     * @return bool True if found and deleted.
     */
    public function revoke(string $token): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM share_links WHERE token = :tok');
        $stmt->execute([':tok' => trim($token)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all links created by an owner.
     *
     * @return list<array<string,mixed>>
     */
    public function forOwner(string $ownerId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, token, target_type, target_id, owner_id, label,
                    views, max_views, expires_at, created_at
             FROM share_links WHERE owner_id = :owner ORDER BY created_at DESC'
        );
        $stmt->execute([':owner' => $ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all expired links (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM share_links WHERE expires_at IS NOT NULL AND expires_at <= :now');
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateNonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }
        return $value;
    }
}
