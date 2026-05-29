<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PledgeDrive — crowdfunding / fundraising drive with monetary pledges.
 *
 * A drive has a funding goal (integer cents) and optional deadline; supporters
 * pledge amounts toward it. Tracks total raised, progress toward goal, distinct
 * backer count, and whether the goal has been reached. Distinct from `Petition`
 * (signature count toward a goal — no money) and `CreditLedger`/`Payout`
 * (accounting primitives): this is the campaign-and-pledges aggregate.
 *
 * ## Usage
 *
 * ```php
 * $pd = new PledgeDrive($pdo);
 *
 * $id = $pd->createDrive('New roof', 500000, deadline: '2026-07-01'); // goal 5000.00
 * $pd->pledge($id, 'alice', 200000); // 2000.00
 * $pd->pledge($id, 'bob',   350000); // 3500.00
 *
 * $pd->raised($id);       // 550000
 * $pd->goalReached($id);  // true
 * $pd->progress($id);     // 1.0 (capped at 1.0)
 * $pd->backerCount($id);  // 2
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE pledge_drives (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(190) NOT NULL,
 *     goal_cents INTEGER      NOT NULL,
 *     deadline   CHAR(10)     NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE pledge_drive_pledges (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     drive_id     BIGINT       NOT NULL,
 *     backer       VARCHAR(190) NOT NULL,
 *     amount_cents INTEGER      NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PledgeDrive
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a drive with a funding goal (integer cents) and optional deadline.
     *
     * @param  string      $name     Drive name.
     * @param  int         $goalCents Funding goal in cents (> 0).
     * @param  string|null $deadline  Optional 'Y-m-d' deadline.
     * @return int                    New drive id.
     * @throws \InvalidArgumentException on empty name or non-positive goal.
     */
    public function createDrive(string $name, int $goalCents, ?string $deadline = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name must not be empty.');
        }
        if ($goalCents <= 0) {
            throw new \InvalidArgumentException('Goal must be positive.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO pledge_drives (name, goal_cents, deadline) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $goalCents, $deadline]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Record a pledge toward a drive.
     *
     * @param  int    $driveId     Drive id (must exist).
     * @param  string $backer      Backer identifier.
     * @param  int    $amountCents Pledge amount in cents (> 0).
     * @return int                 New pledge id.
     * @throws \InvalidArgumentException on unknown drive, empty backer, or non-positive amount.
     */
    public function pledge(int $driveId, string $backer, int $amountCents): int
    {
        $backer = trim($backer);
        if ($backer === '') {
            throw new \InvalidArgumentException('Backer must not be empty.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Pledge amount must be positive.');
        }
        if (!$this->driveExists($driveId)) {
            throw new \InvalidArgumentException("Unknown drive: {$driveId}");
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO pledge_drive_pledges (drive_id, backer, amount_cents) VALUES (?, ?, ?)'
        );
        $stmt->execute([$driveId, $backer, $amountCents]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Total amount raised for a drive (cents).
     */
    public function raised(int $driveId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM pledge_drive_pledges WHERE drive_id = ?'
        );
        $stmt->execute([$driveId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Progress toward the goal as a ratio in [0.0, 1.0] (capped). Returns 0.0
     * for an unknown drive.
     */
    public function progress(int $driveId): float
    {
        $goal = $this->goal($driveId);
        if ($goal === null) {
            return 0.0;
        }
        $ratio = $this->raised($driveId) / $goal;

        return $ratio > 1.0 ? 1.0 : $ratio;
    }

    /**
     * Whether the goal has been reached (raised >= goal). False for unknown drive.
     */
    public function goalReached(int $driveId): bool
    {
        $goal = $this->goal($driveId);

        return $goal !== null && $this->raised($driveId) >= $goal;
    }

    /**
     * Amount still needed to reach the goal (cents); 0 once reached. Returns 0
     * for an unknown drive.
     */
    public function remaining(int $driveId): int
    {
        $goal = $this->goal($driveId);
        if ($goal === null) {
            return 0;
        }
        $left = $goal - $this->raised($driveId);

        return $left > 0 ? $left : 0;
    }

    /**
     * Number of distinct backers for a drive.
     */
    public function backerCount(int $driveId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT backer) FROM pledge_drive_pledges WHERE drive_id = ?'
        );
        $stmt->execute([$driveId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Top backers by total pledged (descending), with their summed amount.
     *
     * @return array<int,array{backer:string,total:int}>
     */
    public function topBackers(int $driveId, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be at least 1.');
        }
        $stmt = $this->db()->prepare(
            'SELECT backer, SUM(amount_cents) AS total FROM pledge_drive_pledges
             WHERE drive_id = ? GROUP BY backer ORDER BY total DESC, backer ASC LIMIT ?'
        );
        $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['backer' => (string)$r['backer'], 'total' => (int)$r['total']];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function goal(int $driveId): ?int
    {
        $stmt = $this->db()->prepare('SELECT goal_cents FROM pledge_drives WHERE id = ?');
        $stmt->execute([$driveId]);
        $g = $stmt->fetchColumn();

        return $g === false ? null : (int)$g;
    }

    private function driveExists(int $driveId): bool
    {
        return $this->goal($driveId) !== null;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
