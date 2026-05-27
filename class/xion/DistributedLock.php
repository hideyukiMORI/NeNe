<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * DB-backed distributed lock with TTL, owner enforcement, and stale-lock reclaim.
 *
 * Suitable for low-to-medium contention workloads (batch jobs, scheduled tasks,
 * deduplication windows). For high-contention hot paths, prefer Redis SETNX.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE distributed_locks (
 *     resource    VARCHAR(255) PRIMARY KEY,
 *     owner       VARCHAR(255) NOT NULL,
 *     expires_at  DATETIME     NOT NULL,
 *     acquired_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 *
 * ## Usage
 *
 * ```php
 * $lock = new DistributedLock();
 * $owner = bin2hex(random_bytes(8)); // unique per process/request
 *
 * if ($lock->acquire('report:monthly', $owner, ttlSeconds: 30)) {
 *     try {
 *         // ... do work ...
 *     } finally {
 *         $lock->release('report:monthly', $owner);
 *     }
 * } else {
 *     // Another process holds the lock — retry later or skip
 * }
 * ```
 *
 * ## Stale lock reclaim
 *
 * If a process crashes while holding a lock, the TTL ensures the lock expires
 * and can be claimed by the next caller. No manual cleanup is required.
 *
 * ## Owner identity
 *
 * Use a per-process or per-request unique string (UUID, random hex) as `$owner`.
 * A fixed hostname like `worker-1` becomes ambiguous after a restart.
 */
final class DistributedLock
{
    private const TABLE = 'distributed_locks';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Try to acquire a lock on $resource for $owner with the given TTL.
     *
     * Returns true if the lock was acquired (including stale-lock reclaim
     * and same-owner re-acquisition). Returns false if another owner holds
     * an unexpired lock.
     *
     * @param string $resource   Unique lock key (e.g. "import:users", "report:2024-01").
     * @param string $owner      Unique identifier for the caller (UUID or random hex).
     * @param int    $ttlSeconds Seconds until the lock expires.
     */
    public function acquire(string $resource, string $owner, int $ttlSeconds = 30): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $now       = $this->now();
        $expiresAt = $this->addSeconds($now, $ttlSeconds);

        $stmt = $db->prepare(
            'SELECT owner, expires_at FROM ' . $this->table .
            ' WHERE resource = :r LIMIT 1'
        );
        $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // No existing lock — try INSERT
            return $this->insert($db, $resource, $owner, $expiresAt, $now);
        }

        $existingOwner     = (string)$row['owner'];
        $existingExpiresAt = (string)$row['expires_at'];

        if ($existingOwner === $owner || $existingExpiresAt <= $now) {
            // Same owner (re-acquire) or expired lock (stale reclaim) — UPDATE
            $stmt = $db->prepare(
                'UPDATE ' . $this->table .
                ' SET owner = :o, expires_at = :e, acquired_at = :a WHERE resource = :r'
            );
            $stmt->bindValue(':o', $owner, PDO::PARAM_STR);
            $stmt->bindValue(':e', $expiresAt, PDO::PARAM_STR);
            $stmt->bindValue(':a', $now, PDO::PARAM_STR);
            $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        }

        // Held by another owner and not expired
        return false;
    }

    /**
     * Release a lock. Only the owner can release their own lock.
     *
     * Returns true if the lock was released.
     * Returns false if the resource is not locked or the owner does not match.
     *
     * @param string $resource Unique lock key.
     * @param string $owner    Must match the owner used in acquire().
     */
    public function release(string $resource, string $owner): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE resource = :r AND owner = :o'
        );
        $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
        $stmt->bindValue(':o', $owner, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Extend the TTL of an existing lock. Only the current owner can renew.
     *
     * Returns true if the lock was successfully renewed.
     * Returns false if the lock does not exist, is expired, or the owner differs.
     *
     * @param string $resource   Unique lock key.
     * @param string $owner      Must match the owner used in acquire().
     * @param int    $ttlSeconds New TTL from now.
     */
    public function renew(string $resource, string $owner, int $ttlSeconds = 30): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $now       = $this->now();
        $expiresAt = $this->addSeconds($now, $ttlSeconds);

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET expires_at = :e WHERE resource = :r AND owner = :o AND expires_at > :n'
        );
        $stmt->bindValue(':e', $expiresAt, PDO::PARAM_STR);
        $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
        $stmt->bindValue(':o', $owner, PDO::PARAM_STR);
        $stmt->bindValue(':n', $now, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether any owner currently holds an unexpired lock on $resource.
     *
     * @param string $resource Unique lock key.
     */
    public function isHeld(string $resource): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $now  = $this->now();
        $stmt = $db->prepare(
            'SELECT 1 FROM ' . $this->table .
            ' WHERE resource = :r AND expires_at > :n LIMIT 1'
        );
        $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
        $stmt->bindValue(':n', $now, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Insert a new lock row. Returns false on UNIQUE violation (concurrent race).
     */
    private function insert(PDO $db, string $resource, string $owner, string $expiresAt, string $now): bool
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO ' . $this->table . ' (resource, owner, expires_at, acquired_at) VALUES (:r, :o, :e, :a)'
            : 'INSERT IGNORE INTO '    . $this->table . ' (resource, owner, expires_at, acquired_at) VALUES (:r, :o, :e, :a)';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':r', $resource, PDO::PARAM_STR);
        $stmt->bindValue(':o', $owner, PDO::PARAM_STR);
        $stmt->bindValue(':e', $expiresAt, PDO::PARAM_STR);
        $stmt->bindValue(':a', $now, PDO::PARAM_STR);

        try {
            $stmt->execute();
        } catch (\PDOException) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Current timestamp as ISO 8601 string (Y-m-d H:i:s).
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Add seconds to an ISO 8601 timestamp string.
     */
    private function addSeconds(string $base, int $seconds): string
    {
        return (new \DateTimeImmutable($base, new \DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }
}
