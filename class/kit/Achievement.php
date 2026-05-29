<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * Achievement — progress-tracked, auto-unlocking achievements per user.
 *
 * Defines achievements with a numeric target ("read 10 articles") and tracks
 * each user's progress, automatically unlocking when progress reaches the
 * target. Distinct from `ProfileBadge` (FT257), which awards a badge in one
 * shot: this models *progress toward* an unlock.
 *
 * Two tables: achievement definitions and per-user progress.
 *
 * ## Usage
 *
 * ```php
 * $a = new Achievement($pdo);
 *
 * $a->define('bookworm', 'Bookworm', target: 10);
 *
 * $a->advance(42, 'bookworm', 7);   // false (7/10)
 * $a->advance(42, 'bookworm', 3);   // true  — just hit 10/10 → unlocked
 * $a->advance(42, 'bookworm');      // false (already unlocked)
 *
 * $a->progress(42, 'bookworm');     // 10
 * $a->isUnlocked(42, 'bookworm');   // true
 * $a->unlockedFor(42);              // ['bookworm']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE achievement_defs (
 *     id     INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code   VARCHAR(100) NOT NULL,
 *     name   VARCHAR(190) NOT NULL DEFAULT '',
 *     target INTEGER      NOT NULL,
 *     UNIQUE (code)
 * );
 * CREATE TABLE achievement_progress (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     BIGINT       NOT NULL,
 *     code        VARCHAR(100) NOT NULL,
 *     progress    INTEGER      NOT NULL DEFAULT 0,
 *     unlocked    INTEGER      NOT NULL DEFAULT 0,
 *     unlocked_at DATETIME     NULL,
 *     UNIQUE (user_id, code)
 * );
 * ```
 */
final class Achievement
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── definitions ─────────────────────────────────────────────────────────────

    /**
     * Define (or update) an achievement. Idempotent per code.
     *
     * @param  string $code   Achievement code.
     * @param  string $name   Display name.
     * @param  int    $target Progress required to unlock (>= 1).
     * @throws \InvalidArgumentException on empty code or target < 1.
     */
    public function define(string $code, string $name, int $target): void
    {
        $code = $this->validate($code, 'Code');
        if ($target < 1) {
            throw new \InvalidArgumentException('Target must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'achievement_defs',
            data:         ['code' => $code, 'name' => trim($name), 'target' => $target],
            conflictCols: ['code'],
            updateCols:   ['name', 'target'],
        );
    }

    // ── progress ────────────────────────────────────────────────────────────────

    /**
     * Add progress for a user; unlock when the target is reached.
     *
     * @param  string      $code Achievement code (must be defined).
     * @param  int         $by   Progress to add (>= 1).
     * @param  string|null $asOf Unlock time; defaults to now.
     * @return bool              True only on the call that crosses into unlocked.
     * @throws \InvalidArgumentException if undefined or $by < 1.
     */
    public function advance(int $userId, string $code, int $by = 1, ?string $asOf = null): bool
    {
        $code = $this->validate($code, 'Code');
        if ($by < 1) {
            throw new \InvalidArgumentException('Increment must be at least 1.');
        }
        $target = $this->target($code);
        if ($target === null) {
            throw new \InvalidArgumentException("Achievement is not defined: {$code}");
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $row         = $this->progressRow($userId, $code);
            $wasUnlocked = $row !== null && (int)$row['unlocked'] === 1;
            $current     = $row === null ? 0 : (int)$row['progress'];
            $new         = min($target, $current + $by);
            $nowUnlocked = $new >= $target;

            DbUpsert::run(
                $db,
                table:        'achievement_progress',
                data:         [
                    'user_id'     => $userId,
                    'code'        => $code,
                    'progress'    => $new,
                    'unlocked'    => $nowUnlocked ? 1 : 0,
                    'unlocked_at' => $nowUnlocked && !$wasUnlocked ? $this->ts($asOf) : ($row['unlocked_at'] ?? null),
                ],
                conflictCols: ['user_id', 'code'],
                updateCols:   ['progress', 'unlocked', 'unlocked_at'],
            );

            if ($ownTransaction) {
                $db->commit();
            }

            return $nowUnlocked && !$wasUnlocked;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * A user's current progress on an achievement (0 if none).
     */
    public function progress(int $userId, string $code): int
    {
        $row = $this->progressRow($userId, $this->validate($code, 'Code'));

        return $row === null ? 0 : (int)$row['progress'];
    }

    /**
     * Whether a user has unlocked an achievement.
     */
    public function isUnlocked(int $userId, string $code): bool
    {
        $row = $this->progressRow($userId, $this->validate($code, 'Code'));

        return $row !== null && (int)$row['unlocked'] === 1;
    }

    /**
     * Progress as a fraction of the target (0.0–1.0), 0.0 if undefined.
     */
    public function progressPct(int $userId, string $code): float
    {
        $code   = $this->validate($code, 'Code');
        $target = $this->target($code);
        if ($target === null) {
            return 0.0;
        }

        return round(min(1.0, $this->progress($userId, $code) / $target), 4);
    }

    /**
     * Codes a user has unlocked, ascending.
     *
     * @return array<int,string>
     */
    public function unlockedFor(int $userId): array
    {
        $stmt = $this->db()->prepare('SELECT code FROM achievement_progress WHERE user_id = ? AND unlocked = 1 ORDER BY code ASC');
        $stmt->execute([$userId]);

        return array_map(static fn ($c): string => (string)$c, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function target(string $code): ?int
    {
        $stmt = $this->db()->prepare('SELECT target FROM achievement_defs WHERE code = ?');
        $stmt->execute([$code]);
        $t = $stmt->fetchColumn();

        return $t === false ? null : (int)$t;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function progressRow(int $userId, string $code): ?array
    {
        $stmt = $this->db()->prepare('SELECT progress, unlocked, unlocked_at FROM achievement_progress WHERE user_id = ? AND code = ?');
        $stmt->execute([$userId, $code]);
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

    private function ts(?string $asOf): string
    {
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid timestamp.');
        }

        return date('Y-m-d H:i:s', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
