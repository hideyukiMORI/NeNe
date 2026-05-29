<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * FileQuarantine — quarantine suspicious or policy-violating files with release/reject workflow.
 *
 * Files are quarantined when flagged for review (e.g. AV scan, policy check).
 * A reviewer then either releases them (safe) or rejects them (removed/destroyed).
 *
 * Status lifecycle: `quarantined` → `released` | `rejected`
 *
 * ## Usage
 *
 * ```php
 * $fq = new FileQuarantine($pdo);
 *
 * // Quarantine a file
 * $id = $fq->quarantine('file-abc', 'user-1', 'antivirus_flag', 'Trojan.Gen detected');
 *
 * // Release (safe to use)
 * $fq->release('file-abc', 'admin-1');
 *
 * // Reject (permanently flagged/removed)
 * $fq->reject('file-abc', 'admin-1', 'confirmed malware');
 *
 * // Check status
 * $fq->status('file-abc'); // 'quarantined'|'released'|'rejected'|null
 *
 * // List all quarantined files
 * $fq->listQuarantined();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE file_quarantine (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     file_id       VARCHAR(255) NOT NULL UNIQUE,
 *     owner_id      VARCHAR(255) NOT NULL DEFAULT '',
 *     reason        VARCHAR(100) NOT NULL DEFAULT '',
 *     notes         TEXT         NOT NULL DEFAULT '',
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'quarantined',
 *     reviewed_by   VARCHAR(255) NOT NULL DEFAULT '',
 *     review_notes  TEXT         NOT NULL DEFAULT '',
 *     quarantined_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     reviewed_at   DATETIME     DEFAULT NULL
 * );
 * ```
 */
final class FileQuarantine
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Quarantine a file for review.
     *
     * If the file is already quarantined, updates the reason and notes.
     *
     * @param  string $fileId   Unique file identifier.
     * @param  string $ownerId  User who owns the file.
     * @param  string $reason   Short reason code (e.g. 'antivirus_flag', 'policy_violation').
     * @param  string $notes    Optional additional details.
     * @return int  The quarantine record ID.
     * @throws \InvalidArgumentException if file_id is empty.
     */
    public function quarantine(
        string $fileId,
        string $ownerId = '',
        string $reason = '',
        string $notes = ''
    ): int {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new \InvalidArgumentException('file_id must not be empty.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO file_quarantine (file_id, owner_id, reason, notes)
                 VALUES (:fid, :oid, :reason, :notes)
                 ON CONFLICT (file_id)
                 DO UPDATE SET reason = excluded.reason,
                               notes = excluded.notes,
                               status = \'quarantined\',
                               reviewed_by = \'\',
                               review_notes = \'\',
                               reviewed_at = NULL'
            )->execute([':fid' => $fileId, ':oid' => $ownerId, ':reason' => $reason, ':notes' => $notes]);
        } else {
            $db->prepare(
                'INSERT INTO file_quarantine (file_id, owner_id, reason, notes)
                 VALUES (:fid, :oid, :reason, :notes)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason),
                                         notes = VALUES(notes),
                                         status = \'quarantined\',
                                         reviewed_by = \'\',
                                         review_notes = \'\',
                                         reviewed_at = NULL'
            )->execute([':fid' => $fileId, ':oid' => $ownerId, ':reason' => $reason, ':notes' => $notes]);
        }

        $stmt = $db->prepare('SELECT id FROM file_quarantine WHERE file_id = :fid LIMIT 1');
        $stmt->execute([':fid' => $fileId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Release a quarantined file (mark as safe).
     *
     * @param  string $reviewedBy  Admin/reviewer identifier.
     * @param  string $reviewNotes Optional notes from reviewer.
     * @return bool True if the file was in 'quarantined' status and was released.
     * @throws \InvalidArgumentException if file_id is empty.
     */
    public function release(string $fileId, string $reviewedBy, string $reviewNotes = ''): bool
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new \InvalidArgumentException('file_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE file_quarantine
             SET status = \'released\', reviewed_by = :by, review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE file_id = :fid AND status = \'quarantined\''
        );
        $stmt->execute([':fid' => $fileId, ':by' => $reviewedBy, ':notes' => $reviewNotes]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a quarantined file (permanently flagged/removed).
     *
     * @param  string $reviewedBy  Admin/reviewer identifier.
     * @param  string $reviewNotes Optional notes from reviewer.
     * @return bool True if the file was in 'quarantined' status and was rejected.
     * @throws \InvalidArgumentException if file_id is empty.
     */
    public function reject(string $fileId, string $reviewedBy, string $reviewNotes = ''): bool
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new \InvalidArgumentException('file_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE file_quarantine
             SET status = \'rejected\', reviewed_by = :by, review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE file_id = :fid AND status = \'quarantined\''
        );
        $stmt->execute([':fid' => $fileId, ':by' => $reviewedBy, ':notes' => $reviewNotes]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the current status of a file.
     *
     * @return 'quarantined'|'released'|'rejected'|null  null if not found.
     */
    public function status(string $fileId): ?string
    {
        $fileId = trim($fileId);
        $stmt   = $this->db()->prepare(
            'SELECT status FROM file_quarantine WHERE file_id = :fid LIMIT 1'
        );
        $stmt->execute([':fid' => $fileId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Get the full quarantine record for a file.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $fileId): ?array
    {
        $fileId = trim($fileId);
        $stmt   = $this->db()->prepare(
            'SELECT id, file_id, owner_id, reason, notes, status,
                    reviewed_by, review_notes, quarantined_at, reviewed_at
             FROM file_quarantine WHERE file_id = :fid LIMIT 1'
        );
        $stmt->execute([':fid' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all files currently in quarantine (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function listQuarantined(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, file_id, owner_id, reason, notes, quarantined_at
             FROM file_quarantine
             WHERE status = \'quarantined\'
             ORDER BY id ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check whether a file is currently under quarantine.
     */
    public function isQuarantined(string $fileId): bool
    {
        return $this->status($fileId) === 'quarantined';
    }

    /**
     * Hard-delete a quarantine record.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $fileId): bool
    {
        $fileId = trim($fileId);
        $stmt   = $this->db()->prepare('DELETE FROM file_quarantine WHERE file_id = :fid');
        $stmt->execute([':fid' => $fileId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
