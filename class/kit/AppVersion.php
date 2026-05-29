<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * AppVersion — deployment/release version history tracking.
 *
 * Records when each version of the application was deployed, to which
 * environment, by whom, and with which git hash. Useful for audit trails,
 * rollback decisions, and release dashboards.
 *
 * ## Usage
 *
 * ```php
 * $av = new AppVersion($pdo);
 *
 * $id = $av->record('1.4.2', 'production', 'a3f9c12', 'deploy-bot');
 * $current = $av->current('production');  // latest for env
 * $history = $av->history('production', 5);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE app_versions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     version     VARCHAR(50)  NOT NULL,
 *     environment VARCHAR(50)  NOT NULL DEFAULT 'production',
 *     git_hash    VARCHAR(40)  NULL,
 *     deployed_by VARCHAR(255) NULL,
 *     note        TEXT         NULL,
 *     deployed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AppVersion
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a new deployment.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty version.
     */
    public function record(
        string $version,
        string $environment = 'production',
        ?string $gitHash = null,
        ?string $deployedBy = null,
        ?string $note = null
    ): int {
        $version     = trim($version);
        $environment = trim($environment) === '' ? 'production' : trim($environment);
        if ($version === '') {
            throw new \InvalidArgumentException('version must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO app_versions (version, environment, git_hash, deployed_by, note, deployed_at)
             VALUES (:ver, :env, :hash, :by, :note, :now)'
        );
        $stmt->execute([
            ':ver'  => $version,
            ':env'  => $environment,
            ':hash' => $gitHash,
            ':by'   => $deployedBy,
            ':note' => $note,
            ':now'  => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a specific version record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, version, environment, git_hash, deployed_by, note, deployed_at
             FROM app_versions WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Return the most recently recorded version for an environment.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $environment = 'production'): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, version, environment, git_hash, deployed_by, note, deployed_at
             FROM app_versions WHERE environment = :env ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':env' => trim($environment)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Return the deployment history for an environment, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $environment = 'production', int $limit = 20): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            'SELECT id, version, environment, git_hash, deployed_by, note, deployed_at
             FROM app_versions WHERE environment = :env ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':env', trim($environment), PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all distinct environments that have been recorded.
     *
     * @return list<string>
     */
    public function environments(): array
    {
        $stmt = $this->db()->query(
            'SELECT DISTINCT environment FROM app_versions ORDER BY environment ASC'
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Delete all version records for an environment.
     *
     * @return int Number of rows deleted.
     */
    public function clearEnvironment(string $environment): int
    {
        $stmt = $this->db()->prepare('DELETE FROM app_versions WHERE environment = :env');
        $stmt->execute([':env' => trim($environment)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
