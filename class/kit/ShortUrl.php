<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ShortUrl — URL shortener with click tracking and optional expiry.
 *
 * Generates short alphanumeric codes for long URLs. Each redirect is recorded
 * for analytics. Supports custom slugs, optional expiry, and active/inactive state.
 *
 * ## Usage
 *
 * ```php
 * $su = new ShortUrl($pdo);
 *
 * // Shorten a URL (auto-generates code)
 * $code = $su->shorten('https://example.com/very/long/path');
 *
 * // Custom slug
 * $code = $su->shorten('https://example.com/promo', slug: 'summer24');
 *
 * // Resolve and record a click
 * $url = $su->resolve($code); // 'https://example.com/...' or null
 *
 * // Stats
 * $clicks = $su->clickCount($code);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE short_urls (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code       VARCHAR(20)  NOT NULL UNIQUE,
 *     target_url TEXT         NOT NULL,
 *     is_active  TINYINT(1)   NOT NULL DEFAULT 1,
 *     expires_at DATETIME     DEFAULT NULL,
 *     clicks     INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ShortUrl
{
    private const CODE_LENGTH  = 7;
    private const CODE_CHARSET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a short URL.
     *
     * @param  string                   $targetUrl  The URL to shorten.
     * @param  string|null              $slug       Optional custom code (must be unique).
     * @param  \DateTimeImmutable|null  $expiresAt  Optional expiry.
     * @return string  The short code.
     * @throws \InvalidArgumentException if target_url is empty.
     * @throws \RuntimeException         if the slug is already taken.
     */
    public function shorten(
        string $targetUrl,
        ?string $slug = null,
        ?\DateTimeImmutable $expiresAt = null
    ): string {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '') {
            throw new \InvalidArgumentException('target_url must not be empty.');
        }

        $code    = $slug !== null ? trim($slug) : $this->generateCode();
        $expStr  = $expiresAt?->format('Y-m-d H:i:s');

        if ($code === '') {
            throw new \InvalidArgumentException('slug must not be empty.');
        }

        try {
            $this->db()->prepare(
                'INSERT INTO short_urls (code, target_url, expires_at) VALUES (:code, :url, :exp)'
            )->execute([':code' => $code, ':url' => $targetUrl, ':exp' => $expStr]);
        } catch (\PDOException $e) {
            if ($slug !== null) {
                throw new \RuntimeException("Slug '{$code}' is already taken.", 0, $e);
            }
            // Retry once on collision for auto-generated codes
            $code = $this->generateCode();
            $this->db()->prepare(
                'INSERT INTO short_urls (code, target_url, expires_at) VALUES (:code, :url, :exp)'
            )->execute([':code' => $code, ':url' => $targetUrl, ':exp' => $expStr]);
        }

        return $code;
    }

    /**
     * Resolve a short code to its target URL and increment the click counter.
     *
     * Returns null if the code does not exist, is inactive, or has expired.
     */
    public function resolve(string $code): ?string
    {
        $code = trim($code);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'SELECT id, target_url FROM short_urls
             WHERE code = :code AND is_active = 1
               AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1'
        );
        $stmt->execute([':code' => $code, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Increment click counter
        $this->db()->prepare(
            'UPDATE short_urls SET clicks = clicks + 1 WHERE id = :id'
        )->execute([':id' => $row['id']]);

        return (string)$row['target_url'];
    }

    /**
     * Look up a short URL record without recording a click.
     *
     * @return array{id: int, code: string, target_url: string, is_active: int, expires_at: string|null, clicks: int, created_at: string}|null
     */
    public function find(string $code): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, code, target_url, is_active, expires_at, clicks, created_at
             FROM short_urls WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the click count for a short code.
     *
     * Returns 0 if the code does not exist.
     */
    public function clickCount(string $code): int
    {
        $row = $this->find($code);
        return $row !== null ? (int)$row['clicks'] : 0;
    }

    /**
     * Deactivate a short URL (it will no longer resolve).
     *
     * @return bool True if the code was found and deactivated.
     */
    public function deactivate(string $code): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE short_urls SET is_active = 0 WHERE code = :code AND is_active = 1'
        );
        $stmt->execute([':code' => trim($code)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate a deactivated short URL.
     *
     * @return bool True if the code was found and reactivated.
     */
    public function reactivate(string $code): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE short_urls SET is_active = 1 WHERE code = :code AND is_active = 0'
        );
        $stmt->execute([':code' => trim($code)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete a short URL record.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $code): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM short_urls WHERE code = :code');
        $stmt->execute([':code' => trim($code)]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function generateCode(): string
    {
        $charset = self::CODE_CHARSET;
        $len     = strlen($charset);
        $code    = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $charset[random_int(0, $len - 1)];
        }
        return $code;
    }
}
