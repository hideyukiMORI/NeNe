<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * Pseudonymizer — stable real-value → pseudonym token mapping for PII.
 *
 * Maps a sensitive value (user id, email, account number) to a stable random
 * pseudonym token within a namespace, so logs, exports, and analytics can use
 * the token instead of the real value while still correlating across records.
 * Authorized callers can `reverse()` a token back to the real value, and
 * `forget()` supports GDPR erasure. Distinct from `RedactionRule` (FT279, which
 * masks text irreversibly) and `ChecksumRegistry` (FT287, integrity hashes).
 *
 * The same `(namespace, value)` always yields the same token; different
 * namespaces keep their mappings independent (e.g. per export job).
 *
 * ## Usage
 *
 * ```php
 * $pz = new Pseudonymizer($pdo);
 *
 * $t1 = $pz->pseudonymize('analytics', 'user-42'); // 'a1b2…'
 * $t2 = $pz->pseudonymize('analytics', 'user-42'); // same token ($t1 === $t2)
 *
 * $pz->reverse('analytics', $t1);  // 'user-42'  (authorized re-identification)
 * $pz->has('analytics', 'user-42'); // true
 * $pz->forget('analytics', 'user-42'); // GDPR erasure
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE pseudonyms (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     namespace  VARCHAR(100) NOT NULL,
 *     real_value VARCHAR(255) NOT NULL,
 *     token      VARCHAR(64)  NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (namespace, real_value),
 *     UNIQUE (namespace, token)
 * );
 * ```
 */
final class Pseudonymizer
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Return the stable pseudonym token for a value, creating one if needed.
     *
     * @param  string $namespace Mapping namespace.
     * @param  string $value     Sensitive value to pseudonymise.
     * @return string            The stable token (same value → same token).
     * @throws \InvalidArgumentException on empty namespace or value.
     */
    public function pseudonymize(string $namespace, string $value): string
    {
        $namespace = $this->validate($namespace, 'Namespace');
        $value     = $this->validate($value, 'Value');

        $existing = $this->tokenFor($namespace, $value);
        if ($existing !== null) {
            return $existing;
        }

        // Insert a fresh token; ON CONFLICT DO NOTHING means the first writer
        // wins under concurrency, so we re-read to return the persisted token.
        $token = bin2hex(random_bytes(16));
        DbUpsert::run(
            $this->db(),
            table:        'pseudonyms',
            data:         ['namespace' => $namespace, 'real_value' => $value, 'token' => $token],
            conflictCols: ['namespace', 'real_value'],
        );

        return $this->tokenFor($namespace, $value) ?? $token;
    }

    /**
     * Resolve a token back to its real value, or null if unknown.
     */
    public function reverse(string $namespace, string $token): ?string
    {
        $namespace = $this->validate($namespace, 'Namespace');
        $stmt      = $this->db()->prepare('SELECT real_value FROM pseudonyms WHERE namespace = ? AND token = ?');
        $stmt->execute([$namespace, $token]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string)$v;
    }

    /**
     * Whether a value already has a pseudonym in a namespace.
     */
    public function has(string $namespace, string $value): bool
    {
        $namespace = $this->validate($namespace, 'Namespace');
        $value     = $this->validate($value, 'Value');

        return $this->tokenFor($namespace, $value) !== null;
    }

    /**
     * Erase a value's mapping (GDPR). No-op if absent.
     */
    public function forget(string $namespace, string $value): void
    {
        $namespace = $this->validate($namespace, 'Namespace');
        $value     = $this->validate($value, 'Value');
        $stmt      = $this->db()->prepare('DELETE FROM pseudonyms WHERE namespace = ? AND real_value = ?');
        $stmt->execute([$namespace, $value]);
    }

    /**
     * Number of mappings in a namespace.
     */
    public function count(string $namespace): int
    {
        $namespace = $this->validate($namespace, 'Namespace');
        $stmt      = $this->db()->prepare('SELECT COUNT(*) FROM pseudonyms WHERE namespace = ?');
        $stmt->execute([$namespace]);

        return (int)$stmt->fetchColumn();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function tokenFor(string $namespace, string $value): ?string
    {
        $stmt = $this->db()->prepare('SELECT token FROM pseudonyms WHERE namespace = ? AND real_value = ?');
        $stmt->execute([$namespace, $value]);
        $t = $stmt->fetchColumn();

        return $t === false ? null : (string)$t;
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
