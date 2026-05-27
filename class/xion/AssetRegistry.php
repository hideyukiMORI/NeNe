<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * AssetRegistry — physical and digital asset inventory with assignment tracking.
 *
 * Manages a catalogue of organisational assets (laptops, licences, equipment,
 * SaaS seats, etc.) including their lifecycle (active → retired) and
 * assignee history.
 *
 * Distinct from:
 * - `InventoryStock` (quantity-based stock/warehouse management)
 * - `StorageQuota`   (per-user storage quota enforcement)
 * - `ResourceReservation` (time-bounded resource booking)
 *
 * ## Usage
 *
 * ```php
 * $ar = new AssetRegistry($pdo);
 *
 * $id = $ar->register('laptop', 'MacBook Pro 14"', 'SN-ABC123');
 * $ar->assign($id, 'user-42', 'Issued on hire');
 * $ar->unassign($id, 'Returned at offboarding');
 * $ar->retire($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE asset_registry (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     asset_type   VARCHAR(100) NOT NULL,
 *     name         TEXT         NOT NULL,
 *     serial       VARCHAR(255) NULL,
 *     assignee_id  VARCHAR(255) NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'available',
 *     meta         TEXT         NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE asset_assignments (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     asset_id     INTEGER      NOT NULL,
 *     assignee_id  VARCHAR(255) NOT NULL,
 *     note         TEXT         NULL,
 *     assigned_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     returned_at  DATETIME     NULL
 * );
 * ```
 */
final class AssetRegistry
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED  = 'assigned';
    public const STATUS_RETIRED   = 'retired';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register a new asset.
     *
     * @return int New asset ID.
     * @throws \InvalidArgumentException on empty assetType or name.
     */
    public function register(
        string $assetType,
        string $name,
        ?string $serial = null,
        ?string $meta = null
    ): int {
        $assetType = trim($assetType);
        $name      = trim($name);
        if ($assetType === '') {
            throw new \InvalidArgumentException('asset_type must not be empty.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO asset_registry (asset_type, name, serial, status, meta, created_at)
             VALUES (:type, :name, :serial, :status, :meta, :now)'
        );
        $stmt->execute([
            ':type'   => $assetType,
            ':name'   => $name,
            ':serial' => $serial,
            ':status' => self::STATUS_AVAILABLE,
            ':meta'   => $meta,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an asset by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM asset_registry WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Assign an asset to a user (AVAILABLE → ASSIGNED).
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty assigneeId.
     */
    public function assign(int $assetId, string $assigneeId, ?string $note = null): bool
    {
        $assigneeId = trim($assigneeId);
        if ($assigneeId === '') {
            throw new \InvalidArgumentException('assignee_id must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE asset_registry
             SET status = :status, assignee_id = :assignee
             WHERE id = :id AND status = :available'
        );
        $stmt->execute([
            ':status'    => self::STATUS_ASSIGNED,
            ':assignee'  => $assigneeId,
            ':id'        => $assetId,
            ':available' => self::STATUS_AVAILABLE,
        ]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->db()->prepare(
            'INSERT INTO asset_assignments (asset_id, assignee_id, note, assigned_at)
             VALUES (:aid, :assignee, :note, :now)'
        )->execute([
            ':aid'      => $assetId,
            ':assignee' => $assigneeId,
            ':note'     => $note,
            ':now'      => $now,
        ]);
        return true;
    }

    /**
     * Unassign an asset and return it to AVAILABLE.
     *
     * @return bool True if found and updated.
     */
    public function unassign(int $assetId, ?string $note = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE asset_registry
             SET status = :status, assignee_id = NULL
             WHERE id = :id AND status = :assigned'
        );
        $stmt->execute([
            ':status'   => self::STATUS_AVAILABLE,
            ':id'       => $assetId,
            ':assigned' => self::STATUS_ASSIGNED,
        ]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Mark the latest open assignment as returned
        $this->db()->prepare(
            'UPDATE asset_assignments SET returned_at = :now, note = COALESCE(:note, note)
             WHERE asset_id = :aid AND returned_at IS NULL'
        )->execute([':now' => $now, ':note' => $note, ':aid' => $assetId]);
        return true;
    }

    /**
     * Retire an asset permanently (any → RETIRED).
     *
     * @return bool True if found and not already retired.
     */
    public function retire(int $assetId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE asset_registry SET status = :status, assignee_id = NULL
             WHERE id = :id AND status != :retired'
        );
        $stmt->execute([
            ':status'  => self::STATUS_RETIRED,
            ':id'      => $assetId,
            ':retired' => self::STATUS_RETIRED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * All assets of a given type.
     *
     * @return list<array<string,mixed>>
     */
    public function byType(string $assetType): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM asset_registry WHERE asset_type = :type ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':type' => trim($assetType)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All assets currently assigned to a user.
     *
     * @return list<array<string,mixed>>
     */
    public function assignedTo(string $assigneeId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM asset_registry WHERE assignee_id = :assignee AND status = :assigned
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':assignee' => trim($assigneeId), ':assigned' => self::STATUS_ASSIGNED]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All available assets.
     *
     * @return list<array<string,mixed>>
     */
    public function available(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM asset_registry WHERE status = :status ORDER BY asset_type ASC, name ASC'
        );
        $stmt->execute([':status' => self::STATUS_AVAILABLE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Full assignment history for an asset, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $assetId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM asset_assignments WHERE asset_id = :aid ORDER BY assigned_at DESC, id DESC'
        );
        $stmt->execute([':aid' => $assetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
