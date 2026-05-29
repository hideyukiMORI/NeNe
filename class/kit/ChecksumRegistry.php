<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * ChecksumRegistry — content integrity / tamper-detection registry.
 *
 * Stores a cryptographic checksum per key (a file path, config blob, exported
 * artifact) so later reads can be verified against the recorded hash to detect
 * corruption or tampering. Supports any algorithm reported by
 * {@see hash_algos()}; defaults to SHA-256.
 *
 * ## Usage
 *
 * ```php
 * $cr = new ChecksumRegistry($pdo);
 *
 * $hash = $cr->put('config.json', $jsonString);   // store + return hash
 * $cr->verify('config.json', $jsonString);         // true (unchanged)
 * $cr->verify('config.json', $tampered);           // false
 *
 * $cr->putHash('big.iso', $precomputedSha256);     // store a known hash
 * $cr->matches('big.iso', $precomputedSha256);     // true
 *
 * $cr->get('config.json');  // ['algo'=>'sha256','checksum'=>'...']
 * $cr->forget('config.json');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE checksums (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ref        VARCHAR(190) NOT NULL,
 *     algo       VARCHAR(20)  NOT NULL DEFAULT 'sha256',
 *     checksum   VARCHAR(128) NOT NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (ref)
 * );
 * ```
 */
final class ChecksumRegistry
{
    public const string DEFAULT_ALGO = 'sha256';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Hash content and store it under a key. Returns the computed checksum.
     *
     * @param  string $key   Registry key.
     * @param  string $content Content to hash.
     * @param  string $algo  Hash algorithm (default sha256).
     * @return string        The stored checksum (lowercase hex).
     * @throws \InvalidArgumentException on empty key or unknown algorithm.
     */
    public function put(string $key, string $content, string $algo = self::DEFAULT_ALGO): string
    {
        $algo     = $this->validateAlgo($algo);
        $checksum = hash($algo, $content);
        $this->store($key, $checksum, $algo);

        return $checksum;
    }

    /**
     * Store a pre-computed checksum under a key (no content hashing).
     *
     * @throws \InvalidArgumentException on empty key/checksum or unknown algorithm.
     */
    public function putHash(string $key, string $checksum, string $algo = self::DEFAULT_ALGO): void
    {
        $algo     = $this->validateAlgo($algo);
        $checksum = strtolower(trim($checksum));
        if ($checksum === '') {
            throw new \InvalidArgumentException('Checksum must not be empty.');
        }
        $this->store($key, $checksum, $algo);
    }

    /**
     * Verify content against the stored checksum for a key.
     *
     * @return bool True if a checksum is stored and matches; false otherwise
     *              (including when the key is unknown).
     */
    public function verify(string $key, string $content): bool
    {
        $row = $this->get($key);
        if ($row === null) {
            return false;
        }

        $computed = hash($row['algo'], $content);

        return hash_equals($row['checksum'], $computed);
    }

    /**
     * Whether a given checksum equals the stored one for a key.
     */
    public function matches(string $key, string $checksum): bool
    {
        $row = $this->get($key);
        if ($row === null) {
            return false;
        }

        return hash_equals($row['checksum'], strtolower(trim($checksum)));
    }

    /**
     * Return the stored algorithm and checksum for a key, or null.
     *
     * @return array{algo:string,checksum:string}|null
     */
    public function get(string $key): ?array
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare('SELECT algo, checksum FROM checksums WHERE ref = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['algo' => (string)$row['algo'], 'checksum' => (string)$row['checksum']];
    }

    /**
     * Whether a checksum is registered for a key.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Remove a key's checksum. No-op if absent.
     */
    public function forget(string $key): void
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare('DELETE FROM checksums WHERE ref = ?');
        $stmt->execute([$key]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function store(string $key, string $checksum, string $algo): void
    {
        $key = $this->validateKey($key);

        DbUpsert::run(
            $this->db(),
            table:        'checksums',
            data:         ['ref' => $key, 'algo' => $algo, 'checksum' => $checksum],
            conflictCols: ['ref'],
            updateCols:   ['algo', 'checksum'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    private function validateAlgo(string $algo): string
    {
        $algo = strtolower(trim($algo));
        if (!in_array($algo, hash_algos(), true)) {
            throw new \InvalidArgumentException("Unknown hash algorithm: {$algo}");
        }

        return $algo;
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Key must not be empty.');
        }

        return $key;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
