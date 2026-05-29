<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * Petition — signature campaign toward a goal.
 *
 * Models a named petition with a signature goal: people `sign()` it (once
 * each, optionally with a comment), and the campaign tracks progress toward
 * the goal and can be `close()`d. Distinct from `DocumentSignature` (FT248, an
 * e-signature *approval* workflow over a specific document) and `VotePoll`
 * (FT239, multi-option voting): this is one-sided public support collection.
 *
 * Two tables: a petition definition (goal, closed flag) and its signatures
 * (unique per signer).
 *
 * ## Usage
 *
 * ```php
 * $p = new Petition($pdo);
 *
 * $p->create('save-the-park', goal: 1000);
 * $p->sign('save-the-park', 'alice', 'Please keep it green!');
 * $p->sign('save-the-park', 'bob');
 *
 * $p->signatureCount('save-the-park'); // 2
 * $p->hasSigned('save-the-park', 'alice'); // true
 * $p->progress('save-the-park');       // ['count'=>2,'goal'=>1000,'reached'=>false,'pct'=>0.2]
 * $p->close('save-the-park');          // no more signatures accepted
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE petitions (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(150) NOT NULL,
 *     goal       INTEGER      NOT NULL,
 *     closed     INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (name)
 * );
 * CREATE TABLE petition_signatures (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     petition  VARCHAR(150) NOT NULL,
 *     signer    VARCHAR(190) NOT NULL,
 *     comment   TEXT         NOT NULL DEFAULT '',
 *     signed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (petition, signer)
 * );
 * ```
 */
final class Petition
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── petition lifecycle ──────────────────────────────────────────────────────

    /**
     * Create (or update) a petition with a signature goal. Re-creating updates
     * the goal and re-opens it.
     *
     * @param  string $name Petition name.
     * @param  int    $goal Target signature count (>= 1).
     * @throws \InvalidArgumentException on empty name or goal < 1.
     */
    public function create(string $name, int $goal): void
    {
        $name = $this->validate($name, 'Name');
        if ($goal < 1) {
            throw new \InvalidArgumentException('Goal must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'petitions',
            data:         ['name' => $name, 'goal' => $goal, 'closed' => 0],
            conflictCols: ['name'],
            updateCols:   ['goal', 'closed'],
        );
    }

    /**
     * Close a petition (no more signatures). No-op if absent.
     */
    public function close(string $name): void
    {
        $name = $this->validate($name, 'Name');
        $stmt = $this->db()->prepare('UPDATE petitions SET closed = 1 WHERE name = ?');
        $stmt->execute([$name]);
    }

    /**
     * Whether a petition is closed (false if unknown).
     */
    public function isClosed(string $name): bool
    {
        $row = $this->petition($this->validate($name, 'Name'));

        return $row !== null && (int)$row['closed'] === 1;
    }

    // ── signing ─────────────────────────────────────────────────────────────────

    /**
     * Sign a petition (once per signer).
     *
     * @param  string $name    Petition name (must exist and be open).
     * @param  string $signer  Signer identifier.
     * @param  string $comment Optional comment.
     * @return bool            True if newly signed; false if already signed.
     * @throws \InvalidArgumentException if the petition is unknown or closed.
     */
    public function sign(string $name, string $signer, string $comment = ''): bool
    {
        $name   = $this->validate($name, 'Name');
        $signer = $this->validate($signer, 'Signer');
        $row    = $this->petition($name);
        if ($row === null) {
            throw new \InvalidArgumentException("Petition does not exist: {$name}");
        }
        if ((int)$row['closed'] === 1) {
            throw new \InvalidArgumentException("Petition is closed: {$name}");
        }
        if ($this->hasSigned($name, $signer)) {
            return false;
        }

        $stmt = $this->db()->prepare('INSERT INTO petition_signatures (petition, signer, comment) VALUES (?, ?, ?)');
        $stmt->execute([$name, $signer, $comment]);

        return true;
    }

    /**
     * Whether a signer has signed a petition.
     */
    public function hasSigned(string $name, string $signer): bool
    {
        $name   = $this->validate($name, 'Name');
        $signer = $this->validate($signer, 'Signer');
        $stmt   = $this->db()->prepare('SELECT 1 FROM petition_signatures WHERE petition = ? AND signer = ? LIMIT 1');
        $stmt->execute([$name, $signer]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Current signature count.
     */
    public function signatureCount(string $name): int
    {
        $name = $this->validate($name, 'Name');
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM petition_signatures WHERE petition = ?');
        $stmt->execute([$name]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Whether the signature goal has been reached.
     */
    public function goalReached(string $name): bool
    {
        $row = $this->petition($this->validate($name, 'Name'));
        if ($row === null) {
            return false;
        }

        return $this->signatureCount($name) >= (int)$row['goal'];
    }

    /**
     * Progress summary, or null if the petition is unknown.
     *
     * @return array{count:int,goal:int,reached:bool,pct:float}|null
     */
    public function progress(string $name): ?array
    {
        $name = $this->validate($name, 'Name');
        $row  = $this->petition($name);
        if ($row === null) {
            return null;
        }

        $goal  = (int)$row['goal'];
        $count = $this->signatureCount($name);

        return [
            'count'   => $count,
            'goal'    => $goal,
            'reached' => $count >= $goal,
            'pct'     => round(min(1.0, $count / $goal), 4),
        ];
    }

    /**
     * Recent signatures (signer + comment), newest first.
     *
     * @param  string   $name  Petition name.
     * @param  int|null $limit Optional cap.
     * @return array<int,array{signer:string,comment:string}>
     */
    public function signatures(string $name, ?int $limit = null): array
    {
        $name = $this->validate($name, 'Name');
        $sql  = 'SELECT signer, comment FROM petition_signatures WHERE petition = ? ORDER BY id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([$name]);

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['signer' => (string)$r['signer'], 'comment' => (string)$r['comment']];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function petition(string $name): ?array
    {
        $stmt = $this->db()->prepare('SELECT goal, closed FROM petitions WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function validate(string $value, string $label): string
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
