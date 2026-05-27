<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * GeoBlocker — country-level access control via a configurable allow/block list.
 *
 * Operates in two modes:
 * - **blocklist** (default): all countries are allowed except those explicitly blocked.
 * - **allowlist**: all countries are blocked except those explicitly allowed.
 *
 * Country codes follow ISO 3166-1 alpha-2 (two uppercase letters, e.g. "JP", "US").
 *
 * ## Usage
 *
 * ```php
 * $gb = new GeoBlocker($pdo);
 *
 * // Blocklist mode: block specific countries
 * $gb->block('CN');
 * $gb->block('RU');
 * $gb->isAllowed('JP'); // true
 * $gb->isAllowed('CN'); // false
 *
 * // Allowlist mode: only permit specific countries
 * $gb->setMode('allowlist');
 * $gb->allow('JP');
 * $gb->allow('US');
 * $gb->isAllowed('JP'); // true
 * $gb->isAllowed('DE'); // false
 *
 * // Lookup country from IP (no MaxMind required — caller supplies the code)
 * $code = lookupCountryFromIp($_SERVER['REMOTE_ADDR']); // caller's concern
 * $gb->isAllowed($code);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE geo_blocker_settings (
 *     key_name  VARCHAR(50)  NOT NULL PRIMARY KEY,
 *     value     VARCHAR(255) NOT NULL
 * );
 *
 * CREATE TABLE geo_blocker_countries (
 *     country_code CHAR(2)  NOT NULL PRIMARY KEY,
 *     list_type    VARCHAR(10) NOT NULL  -- 'block' or 'allow'
 * );
 * ```
 */
final class GeoBlocker
{
    private const MODE_BLOCKLIST = 'blocklist';
    private const MODE_ALLOWLIST = 'allowlist';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── mode ──────────────────────────────────────────────────────────────────

    /**
     * Set operating mode: 'blocklist' (default) or 'allowlist'.
     *
     * @throws \InvalidArgumentException if mode is not 'blocklist' or 'allowlist'.
     */
    public function setMode(string $mode): void
    {
        if ($mode !== self::MODE_BLOCKLIST && $mode !== self::MODE_ALLOWLIST) {
            throw new \InvalidArgumentException("mode must be 'blocklist' or 'allowlist'.");
        }
        $db = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO geo_blocker_settings (key_name, value) VALUES (:key, :val)
                 ON CONFLICT (key_name) DO UPDATE SET value = :val2'
            )->execute([':key' => 'mode', ':val' => $mode, ':val2' => $mode]);
        } else {
            $db->prepare(
                'INSERT INTO geo_blocker_settings (key_name, value) VALUES (:key, :val)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            )->execute([':key' => 'mode', ':val' => $mode]);
        }
    }

    /**
     * Get the current operating mode.
     *
     * @return 'blocklist'|'allowlist'
     */
    public function getMode(): string
    {
        $stmt = $this->db()->prepare(
            "SELECT value FROM geo_blocker_settings WHERE key_name = 'mode' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : self::MODE_BLOCKLIST;
    }

    // ── allow / block ─────────────────────────────────────────────────────────

    /**
     * Add a country code to the allow list (for allowlist mode).
     *
     * @throws \InvalidArgumentException if code is not a valid ISO 3166-1 alpha-2 string.
     */
    public function allow(string $countryCode): void
    {
        $this->upsertCountry($this->validateCode($countryCode), 'allow');
    }

    /**
     * Add a country code to the block list (for blocklist mode).
     *
     * @throws \InvalidArgumentException if code is not a valid ISO 3166-1 alpha-2 string.
     */
    public function block(string $countryCode): void
    {
        $this->upsertCountry($this->validateCode($countryCode), 'block');
    }

    /**
     * Remove a country from both allow and block lists.
     *
     * @return bool True if a row was removed.
     */
    public function remove(string $countryCode): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM geo_blocker_countries WHERE country_code = :code'
        );
        $stmt->execute([':code' => $this->validateCode($countryCode)]);
        return $stmt->rowCount() > 0;
    }

    // ── isAllowed ─────────────────────────────────────────────────────────────

    /**
     * Determine whether a country code is allowed given the current mode and lists.
     *
     * @throws \InvalidArgumentException if code is not valid.
     */
    public function isAllowed(string $countryCode): bool
    {
        $code = $this->validateCode($countryCode);
        $mode = $this->getMode();

        $stmt = $this->db()->prepare(
            'SELECT list_type FROM geo_blocker_countries WHERE country_code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $listType = $stmt->fetchColumn();

        if ($mode === self::MODE_BLOCKLIST) {
            // Blocked if explicitly in the block list
            return $listType !== 'block';
        }

        // Allowlist mode: allowed only if explicitly in the allow list
        return $listType === 'allow';
    }

    /**
     * List all country codes registered in the allow list.
     *
     * @return list<string>
     */
    public function allowList(): array
    {
        $stmt = $this->db()->prepare(
            "SELECT country_code FROM geo_blocker_countries WHERE list_type = 'allow' ORDER BY country_code ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * List all country codes registered in the block list.
     *
     * @return list<string>
     */
    public function blockList(): array
    {
        $stmt = $this->db()->prepare(
            "SELECT country_code FROM geo_blocker_countries WHERE list_type = 'block' ORDER BY country_code ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Clear all entries from the allow or block list (or both).
     *
     * @param 'allow'|'block'|'all' $listType
     */
    public function clear(string $listType = 'all'): void
    {
        if ($listType === 'all') {
            $this->db()->exec('DELETE FROM geo_blocker_countries');
        } else {
            $this->db()->prepare(
                'DELETE FROM geo_blocker_countries WHERE list_type = :type'
            )->execute([':type' => $listType]);
        }
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            throw new \InvalidArgumentException(
                "country_code must be a 2-letter ISO 3166-1 alpha-2 code (e.g. 'JP')."
            );
        }
        return $code;
    }

    private function upsertCountry(string $code, string $listType): void
    {
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO geo_blocker_countries (country_code, list_type) VALUES (:code, :type)
                 ON CONFLICT (country_code) DO UPDATE SET list_type = :type2'
            )->execute([':code' => $code, ':type' => $listType, ':type2' => $listType]);
        } else {
            $db->prepare(
                'INSERT INTO geo_blocker_countries (country_code, list_type) VALUES (:code, :type)
                 ON DUPLICATE KEY UPDATE list_type = VALUES(list_type)'
            )->execute([':code' => $code, ':type' => $listType]);
        }
    }
}
