<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Track user acceptance of terms of service, privacy policies, and other agreements.
 *
 * Each term has a name (e.g. 'tos', 'privacy') and a version string.
 * The acceptance row is immutable — once a user accepts a version, the record
 * is kept for audit. A new version triggers a new acceptance requirement.
 *
 * ## Usage
 *
 * ```php
 * $tc = new TermConsent($pdo);
 *
 * // User accepts ToS v2.0
 * $tc->accept('user-1', 'tos', '2.0');
 *
 * // Check if accepted
 * $tc->hasAccepted('user-1', 'tos', '2.0'); // true
 *
 * // Check if up-to-date (requires specific version)
 * $current = '2.1';
 * $tc->hasAccepted('user-1', 'tos', $current); // false — new version
 *
 * // What version did the user accept?
 * $tc->acceptedVersion('user-1', 'tos'); // '2.0' (most recent accepted)
 *
 * // All accepted terms for a user
 * $tc->all('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE term_consents (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     term_name   VARCHAR(100) NOT NULL,
 *     version     VARCHAR(50)  NOT NULL,
 *     accepted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
 *     UNIQUE (user_id, term_name, version)
 * );
 * ```
 */
final class TermConsent
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a user's acceptance of a term version.
     *
     * Idempotent — accepting the same version twice is a no-op.
     *
     * @param string $userId    User accepting the term.
     * @param string $termName  Identifier (e.g. 'tos', 'privacy', 'cookie').
     * @param string $version   Version string (e.g. '2.0', '2024-01-15').
     * @param string $ipAddress Optional IP address for audit trail.
     * @return bool True if a new acceptance was recorded.
     * @throws \InvalidArgumentException if termName or version is empty.
     */
    public function accept(
        string $userId,
        string $termName,
        string $version,
        string $ipAddress = ''
    ): bool {
        [$termName, $version] = $this->normalise($termName, $version);

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO term_consents (user_id, term_name, version, ip_address)
               VALUES (:user, :name, :version, :ip)'
            : 'INSERT IGNORE INTO term_consents (user_id, term_name, version, ip_address)
               VALUES (:user, :name, :version, :ip)';

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user'    => $userId,
            ':name'    => $termName,
            ':version' => $version,
            ':ip'      => trim($ipAddress),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a user has accepted a specific version of a term.
     */
    public function hasAccepted(string $userId, string $termName, string $version): bool
    {
        [$termName, $version] = $this->normalise($termName, $version);
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM term_consents
             WHERE user_id = :user AND term_name = :name AND version = :version
             LIMIT 1'
        );
        $stmt->execute([':user' => $userId, ':name' => $termName, ':version' => $version]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return the most recently accepted version of a term by a user, or null.
     */
    public function acceptedVersion(string $userId, string $termName): ?string
    {
        $termName = trim($termName);
        $stmt     = $this->db()->prepare(
            'SELECT version FROM term_consents
             WHERE user_id = :user AND term_name = :name
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':user' => $userId, ':name' => $termName]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Return all acceptance records for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM term_consents WHERE user_id = :user ORDER BY accepted_at DESC'
        );
        $stmt->execute([':user' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all acceptance records for a specific term (across all users).
     *
     * Useful for auditing who has accepted a particular version.
     *
     * @return list<array<string, mixed>>
     */
    public function acceptances(string $termName, string $version): array
    {
        [$termName, $version] = $this->normalise($termName, $version);
        $stmt = $this->db()->prepare(
            'SELECT * FROM term_consents
             WHERE term_name = :name AND version = :version
             ORDER BY accepted_at ASC'
        );
        $stmt->execute([':name' => $termName, ':version' => $version]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $termName, string $version): array
    {
        $termName = trim($termName);
        $version  = trim($version);
        if ($termName === '') {
            throw new \InvalidArgumentException('term_name must not be empty.');
        }
        if ($version === '') {
            throw new \InvalidArgumentException('version must not be empty.');
        }
        return [$termName, $version];
    }
}
