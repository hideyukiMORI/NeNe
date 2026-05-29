<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Endorsement — peer skill endorsements between users.
 *
 * Lets users endorse one another for named skills (LinkedIn-style), with one
 * endorsement per (subject, skill, endorser) and self-endorsement disallowed.
 * Supports per-skill counts and a user's top skills by endorsement volume.
 *
 * ## Usage
 *
 * ```php
 * $e = new Endorsement($pdo);
 *
 * $e->endorse(subjectUser: 1, skill: 'PHP', endorser: 2);
 * $e->endorse(subjectUser: 1, skill: 'PHP', endorser: 3);
 * $e->endorse(subjectUser: 1, skill: 'SQL', endorser: 2);
 *
 * $e->count(1, 'PHP');        // 2
 * $e->hasEndorsed(1, 'PHP', 2); // true
 * $e->topSkills(1);           // [['skill'=>'PHP','count'=>2], ['skill'=>'SQL','count'=>1]]
 * $e->withdraw(1, 'PHP', 2);  // endorser 2 takes it back
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE endorsements (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     subject_user BIGINT       NOT NULL,
 *     skill        VARCHAR(100) NOT NULL,
 *     endorser     BIGINT       NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (subject_user, skill, endorser)
 * );
 * ```
 */
final class Endorsement
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Endorse a user for a skill. Idempotent per (subject, skill, endorser).
     *
     * @param  int    $subjectUser User being endorsed.
     * @param  string $skill       Skill name.
     * @param  int    $endorser    User giving the endorsement.
     * @return bool                True if newly recorded; false if it already existed.
     * @throws \InvalidArgumentException on empty skill or self-endorsement.
     */
    public function endorse(int $subjectUser, string $skill, int $endorser): bool
    {
        $skill = $this->validateSkill($skill);
        if ($subjectUser === $endorser) {
            throw new \InvalidArgumentException('A user cannot endorse themselves.');
        }
        if ($this->hasEndorsed($subjectUser, $skill, $endorser)) {
            return false;
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO endorsements (subject_user, skill, endorser) VALUES (?, ?, ?)'
        );
        $stmt->execute([$subjectUser, $skill, $endorser]);

        return true;
    }

    /**
     * Whether a specific endorser has endorsed a subject's skill.
     */
    public function hasEndorsed(int $subjectUser, string $skill, int $endorser): bool
    {
        $skill = $this->validateSkill($skill);
        $stmt  = $this->db()->prepare(
            'SELECT 1 FROM endorsements WHERE subject_user = ? AND skill = ? AND endorser = ? LIMIT 1'
        );
        $stmt->execute([$subjectUser, $skill, $endorser]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Number of endorsements a subject has for a skill.
     */
    public function count(int $subjectUser, string $skill): int
    {
        $skill = $this->validateSkill($skill);
        $stmt  = $this->db()->prepare(
            'SELECT COUNT(*) FROM endorsements WHERE subject_user = ? AND skill = ?'
        );
        $stmt->execute([$subjectUser, $skill]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Endorser user ids for a subject's skill, ascending.
     *
     * @return array<int,int>
     */
    public function endorsers(int $subjectUser, string $skill): array
    {
        $skill = $this->validateSkill($skill);
        $stmt  = $this->db()->prepare(
            'SELECT endorser FROM endorsements WHERE subject_user = ? AND skill = ? ORDER BY endorser ASC'
        );
        $stmt->execute([$subjectUser, $skill]);

        return array_map(static fn ($id): int => (int)$id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * A subject's skills with endorsement counts, most-endorsed first.
     *
     * @param  int      $subjectUser Subject user.
     * @param  int|null $limit       Optional cap.
     * @return array<int,array{skill:string,count:int}>
     */
    public function topSkills(int $subjectUser, ?int $limit = null): array
    {
        $sql = 'SELECT skill, COUNT(*) AS c FROM endorsements WHERE subject_user = ?
                GROUP BY skill ORDER BY c DESC, skill ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([$subjectUser]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['skill' => (string)$row['skill'], 'count' => (int)$row['c']];
        }

        return $out;
    }

    /**
     * Withdraw an endorsement. No-op if it does not exist.
     */
    public function withdraw(int $subjectUser, string $skill, int $endorser): void
    {
        $skill = $this->validateSkill($skill);
        $stmt  = $this->db()->prepare(
            'DELETE FROM endorsements WHERE subject_user = ? AND skill = ? AND endorser = ?'
        );
        $stmt->execute([$subjectUser, $skill, $endorser]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validateSkill(string $skill): string
    {
        $skill = trim($skill);
        if ($skill === '') {
            throw new \InvalidArgumentException('Skill must not be empty.');
        }

        return $skill;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
