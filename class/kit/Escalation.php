<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Escalation — tiered escalation ladder for a work item.
 *
 * Drives a reference (ticket, incident, alert) up an ordered ladder of levels
 * (L1 → L2 → … → Lmax) and resolves it. Each step is a single guarded UPDATE,
 * so a level never exceeds the ceiling and a resolved case cannot be escalated.
 * Distinct from `IncidentLog` (records incidents with severity/lifecycle) and
 * `SlaTracker` (deadline-breach detection): this is the who-handles-it-next
 * tier-progression state machine.
 *
 * ## Usage
 *
 * ```php
 * $e = new Escalation($pdo);
 *
 * $e->open('ticket-99', maxLevel: 3); // starts at L1
 * $e->escalate('ticket-99');          // true  → L2
 * $e->escalate('ticket-99');          // true  → L3
 * $e->escalate('ticket-99');          // false — already at ceiling
 * $e->atMaxLevel('ticket-99');        // true
 * $e->resolve('ticket-99');           // true
 * $e->escalate('ticket-99');          // false — resolved
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE escalation_cases (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reference  VARCHAR(190) NOT NULL,
 *     level      INTEGER      NOT NULL DEFAULT 1,
 *     max_level  INTEGER      NOT NULL,
 *     status     VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     opened_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME     NULL,
 *     UNIQUE (reference)
 * );
 * ```
 */
final class Escalation
{
    private const OPEN     = 'open';
    private const RESOLVED = 'resolved';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open an escalation case at level 1.
     *
     * @param  string $reference Work-item reference.
     * @param  int    $maxLevel  Highest level the ladder reaches (>= 1).
     * @return int               New case id.
     * @throws \InvalidArgumentException on empty reference, max < 1, or an
     *                                   existing case for the reference.
     */
    public function open(string $reference, int $maxLevel = 3): int
    {
        $reference = $this->nonEmpty($reference, 'Reference');
        if ($maxLevel < 1) {
            throw new \InvalidArgumentException('Max level must be at least 1.');
        }
        if ($this->caseId($reference) !== null) {
            throw new \InvalidArgumentException("Case already exists: {$reference}");
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO escalation_cases (reference, level, max_level, status) VALUES (?, 1, ?, ?)'
        );
        $stmt->execute([$reference, $maxLevel, self::OPEN]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Escalate one level, if open and below the ceiling.
     *
     * @return bool True if the level advanced; false if at the ceiling,
     *              resolved, or unknown.
     */
    public function escalate(string $reference): bool
    {
        $reference = $this->nonEmpty($reference, 'Reference');

        $stmt = $this->db()->prepare(
            'UPDATE escalation_cases
                SET level = level + 1, updated_at = CURRENT_TIMESTAMP
              WHERE reference = ? AND status = ? AND level < max_level'
        );
        $stmt->execute([$reference, self::OPEN]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Resolve an open case.
     *
     * @return bool True if it was open (now resolved); false if already
     *              resolved or unknown.
     */
    public function resolve(string $reference): bool
    {
        $reference = $this->nonEmpty($reference, 'Reference');

        $stmt = $this->db()->prepare(
            'UPDATE escalation_cases
                SET status = ?, updated_at = CURRENT_TIMESTAMP
              WHERE reference = ? AND status = ?'
        );
        $stmt->execute([self::RESOLVED, $reference, self::OPEN]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Current level, or null if the case does not exist.
     */
    public function level(string $reference): ?int
    {
        $row = $this->row($reference);

        return $row === null ? null : $row['level'];
    }

    /**
     * Whether a case is resolved. False if it does not exist.
     */
    public function isResolved(string $reference): bool
    {
        $row = $this->row($reference);

        return $row !== null && $row['status'] === self::RESOLVED;
    }

    /**
     * Whether an open case sits at its ceiling. False if resolved or unknown.
     */
    public function atMaxLevel(string $reference): bool
    {
        $row = $this->row($reference);

        return $row !== null && $row['status'] === self::OPEN && $row['level'] >= $row['max_level'];
    }

    /**
     * Open cases, most-escalated first (level DESC, then reference).
     *
     * @return array<int,array{reference:string,level:int,max_level:int}>
     */
    public function activeCases(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT reference, level, max_level FROM escalation_cases
              WHERE status = ? ORDER BY level DESC, reference ASC'
        );
        $stmt->execute([self::OPEN]);

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'reference' => (string)$r['reference'],
                'level'     => (int)$r['level'],
                'max_level' => (int)$r['max_level'],
            ];
        }

        return $out;
    }

    /**
     * Number of open cases currently at a given level.
     */
    public function countAtLevel(int $level): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM escalation_cases WHERE status = ? AND level = ?'
        );
        $stmt->execute([self::OPEN, $level]);

        return (int)$stmt->fetchColumn();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array{level:int,max_level:int,status:string}|null
     */
    private function row(string $reference): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT level, max_level, status FROM escalation_cases WHERE reference = ?'
        );
        $stmt->execute([$this->nonEmpty($reference, 'Reference')]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return [
            'level'     => (int)$r['level'],
            'max_level' => (int)$r['max_level'],
            'status'    => (string)$r['status'],
        ];
    }

    private function caseId(string $reference): ?int
    {
        $stmt = $this->db()->prepare('SELECT id FROM escalation_cases WHERE reference = ?');
        $stmt->execute([$reference]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    private function nonEmpty(string $value, string $label): string
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
