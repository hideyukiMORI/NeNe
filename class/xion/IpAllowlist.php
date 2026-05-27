<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * IpAllowlist — per-resource IP allowlist with CIDR range support.
 *
 * Controls which IPs may access a named resource (e.g. API endpoint, admin panel).
 * Supports exact IPv4/IPv6 matches and CIDR notation.
 *
 * ## Usage
 *
 * ```php
 * $ia = new IpAllowlist($pdo);
 *
 * // Add IPs to a resource allowlist
 * $ia->add('admin-panel', '192.168.1.0/24');
 * $ia->add('admin-panel', '10.0.0.1');
 *
 * // Check access
 * $ia->isAllowed('admin-panel', '192.168.1.42'); // true
 * $ia->isAllowed('admin-panel', '1.2.3.4');      // false
 *
 * // List entries
 * $ia->list('admin-panel');
 *
 * // Remove
 * $ia->remove('admin-panel', '10.0.0.1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE ip_allowlist (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource     VARCHAR(255) NOT NULL,
 *     cidr         VARCHAR(50)  NOT NULL,
 *     label        VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (resource, cidr)
 * );
 * ```
 */
final class IpAllowlist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an IP or CIDR range to a resource allowlist.
     *
     * Idempotent — adding the same CIDR twice is a no-op.
     *
     * @throws \InvalidArgumentException if resource is empty or CIDR is invalid.
     */
    public function add(string $resource, string $cidr, string $label = ''): void
    {
        $resource = trim($resource);
        $cidr     = trim($cidr);
        if ($resource === '') {
            throw new \InvalidArgumentException('resource must not be empty.');
        }
        if (!$this->isValidCidr($cidr)) {
            throw new \InvalidArgumentException("Invalid IP/CIDR: '{$cidr}'.");
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO ip_allowlist (resource, cidr, label) VALUES (:res, :cidr, :label)'
            : 'INSERT IGNORE INTO ip_allowlist (resource, cidr, label) VALUES (:res, :cidr, :label)';
        $db->prepare($sql)->execute([':res' => $resource, ':cidr' => $cidr, ':label' => $label]);
    }

    /**
     * Remove an entry from the allowlist.
     *
     * @return bool True if the entry existed and was removed.
     */
    public function remove(string $resource, string $cidr): bool
    {
        $resource = trim($resource);
        $cidr     = trim($cidr);
        $stmt     = $this->db()->prepare(
            'DELETE FROM ip_allowlist WHERE resource = :res AND cidr = :cidr'
        );
        $stmt->execute([':res' => $resource, ':cidr' => $cidr]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if an IP address is allowed to access a resource.
     *
     * Matches exact IPs and CIDR ranges.
     *
     * @throws \InvalidArgumentException if the IP is not a valid address.
     */
    public function isAllowed(string $resource, string $ip): bool
    {
        $resource = trim($resource);
        $ip       = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Invalid IP address: '{$ip}'.");
        }

        $entries = $this->list($resource);
        foreach ($entries as $entry) {
            if ($this->matchesCidr($ip, (string)$entry['cidr'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * List all allowlist entries for a resource.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $resource): array
    {
        $resource = trim($resource);
        $stmt     = $this->db()->prepare(
            'SELECT id, resource, cidr, label, created_at
             FROM ip_allowlist WHERE resource = :res ORDER BY id ASC'
        );
        $stmt->execute([':res' => $resource]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check if a specific CIDR/IP is explicitly listed.
     */
    public function has(string $resource, string $cidr): bool
    {
        $resource = trim($resource);
        $cidr     = trim($cidr);
        $stmt     = $this->db()->prepare(
            'SELECT COUNT(*) FROM ip_allowlist WHERE resource = :res AND cidr = :cidr'
        );
        $stmt->execute([':res' => $resource, ':cidr' => $cidr]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count entries for a resource.
     */
    public function count(string $resource): int
    {
        $resource = trim($resource);
        $stmt     = $this->db()->prepare(
            'SELECT COUNT(*) FROM ip_allowlist WHERE resource = :res'
        );
        $stmt->execute([':res' => $resource]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Clear all entries for a resource.
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $resource): int
    {
        $resource = trim($resource);
        $stmt     = $this->db()->prepare('DELETE FROM ip_allowlist WHERE resource = :res');
        $stmt->execute([':res' => $resource]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function isValidCidr(string $cidr): bool
    {
        if (str_contains($cidr, '/')) {
            [$ip, $prefix] = explode('/', $cidr, 2);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
            return is_numeric($prefix) && (int)$prefix >= 0 && (int)$prefix <= $maxPrefix;
        }
        return (bool)filter_var($cidr, FILTER_VALIDATE_IP);
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int)$prefix;

        // Only handle IPv4 for CIDR matching (IPv6 exact match works above)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip === $network; // IPv6: exact match only for CIDR
        }

        $ipLong      = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false) {
            return false;
        }
        $mask = $prefix > 0 ? ~((1 << (32 - $prefix)) - 1) : 0;
        return ($ipLong & $mask) === ($networkLong & $mask);
    }
}
