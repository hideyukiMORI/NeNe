<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DailyReward — once-per-day claimable reward with a consecutive-day streak.
 *
 * Lets a user claim a reward at most once per calendar day (daily login bonus,
 * spin-the-wheel, etc.) and tracks the streak of consecutive claim days.
 * Distinct from `DailyStreak` (FT255, which counts activity days) and
 * `PointBalance` (FT213, a points ledger): this records the *claim grant*
 * itself with one-per-day enforcement.
 *
 * ## Usage
 *
 * ```php
 * $dr = new DailyReward($pdo);
 *
 * $dr->claim(42, reward: 10, asOf: '2026-06-01'); // true — granted
 * $dr->claim(42, reward: 10, asOf: '2026-06-01'); // false — already claimed today
 * $dr->claim(42, reward: 20, asOf: '2026-06-02'); // true
 *
 * $dr->claimedToday(42, '2026-06-02'); // true
 * $dr->canClaim(42, '2026-06-03');     // true
 * $dr->claimStreak(42, '2026-06-02');  // 2 (Jun 1 + 2 consecutive)
 * $dr->totalClaimed(42);               // 30
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE daily_reward_claims (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    BIGINT   NOT NULL,
 *     claim_date CHAR(10) NOT NULL,
 *     reward     INTEGER  NOT NULL DEFAULT 0,
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, claim_date)
 * );
 * ```
 */
final class DailyReward
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Claim the day's reward. At most once per calendar day per user.
     *
     * @param  int         $userId User id.
     * @param  int         $reward Reward amount granted (>= 0).
     * @param  string|null $asOf   Reference date 'Y-m-d' (or parseable); defaults to today.
     * @return bool                True if newly claimed; false if already claimed today.
     * @throws \InvalidArgumentException on negative reward or bad date.
     */
    public function claim(int $userId, int $reward, ?string $asOf = null): bool
    {
        if ($reward < 0) {
            throw new \InvalidArgumentException('Reward must not be negative.');
        }
        $day = $this->day($asOf);
        if ($this->claimedOn($userId, $day)) {
            return false;
        }

        $stmt = $this->db()->prepare('INSERT INTO daily_reward_claims (user_id, claim_date, reward) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $day, $reward]);

        return true;
    }

    /**
     * Whether the user has claimed today's reward.
     */
    public function claimedToday(int $userId, ?string $asOf = null): bool
    {
        return $this->claimedOn($userId, $this->day($asOf));
    }

    /**
     * Whether the user can claim now (has not claimed today).
     */
    public function canClaim(int $userId, ?string $asOf = null): bool
    {
        return !$this->claimedToday($userId, $asOf);
    }

    /**
     * The user's most recent claim date, or null.
     */
    public function lastClaim(int $userId): ?string
    {
        $stmt = $this->db()->prepare('SELECT MAX(claim_date) FROM daily_reward_claims WHERE user_id = ?');
        $stmt->execute([$userId]);
        $d = $stmt->fetchColumn();

        return $d === null || $d === false ? null : (string)$d;
    }

    /**
     * Total reward amount the user has claimed.
     */
    public function totalClaimed(int $userId): int
    {
        $stmt = $this->db()->prepare('SELECT COALESCE(SUM(reward), 0) FROM daily_reward_claims WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Length of the current consecutive-day claim streak ending at the
     * reference day (or the day before, if today is unclaimed).
     *
     * @param int         $userId User id.
     * @param string|null $asOf   Reference date; defaults to today.
     */
    public function claimStreak(int $userId, ?string $asOf = null): int
    {
        $cursor = new \DateTimeImmutable($this->day($asOf));
        // If today is not claimed, the streak (if any) ends yesterday.
        if (!$this->claimedOn($userId, $cursor->format('Y-m-d'))) {
            $cursor = $cursor->sub(new \DateInterval('P1D'));
        }

        $streak = 0;
        while ($this->claimedOn($userId, $cursor->format('Y-m-d'))) {
            $streak++;
            $cursor = $cursor->sub(new \DateInterval('P1D'));
        }

        return $streak;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function claimedOn(int $userId, string $day): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM daily_reward_claims WHERE user_id = ? AND claim_date = ? LIMIT 1');
        $stmt->execute([$userId, $day]);

        return $stmt->fetchColumn() !== false;
    }

    private function day(?string $asOf): string
    {
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return date('Y-m-d', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
