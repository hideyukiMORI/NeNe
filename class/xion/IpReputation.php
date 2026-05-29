<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * IpReputation — running reputation score per IP address.
 *
 * Accumulates a numeric score per IP from observed behaviour: failed logins,
 * spammy posts, or rate-limit hits push it up (worse); good behaviour or
 * decay can pull it down. Callers then gate on a threshold. Distinct from
 * `IpAllowlist` / `IpBlocklist` (FT121/FT148), which are hard binary lists —
 * this is the soft, additive signal that can *feed* a blocklist decision.
 *
 * Higher score = worse reputation. Score adjustments are applied atomically so
 * concurrent observers accumulate correctly.
 *
 * ## Usage
 *
 * ```php
 * $rep = new IpReputation($pdo);
 *
 * $rep->penalize('203.0.113.5', 10);   // failed login
 * $rep->penalize('203.0.113.5', 5);    // another → score 15
 * $rep->reward('203.0.113.5', 3);      // good behaviour → score 12
 *
 * $rep->score('203.0.113.5');          // 12
 * $rep->isBad('203.0.113.5', 20);      // false (below threshold)
 * $rep->worst(10);                     // worst offenders
 * $rep->purgeBelow(1);                 // housekeeping: drop near-zero scores
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE ip_reputation (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ip         VARCHAR(45) NOT NULL,
 *     score      INTEGER     NOT NULL DEFAULT 0,
 *     updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (ip)
 * );
 * ```
 */
final class IpReputation
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add $delta to an IP's score (may be negative) and return the new score.
     *
     * Applied atomically (read-modify-write in a transaction).
     *
     * @param  string $ip    IP address.
     * @param  int    $delta Amount to add (negative to improve reputation).
     * @return int           The resulting score.
     * @throws \InvalidArgumentException on empty IP.
     */
    public function adjust(string $ip, int $delta): int
    {
        $ip             = $this->validateIp($ip);
        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $current = $this->rawScore($ip);
            $new     = $current + $delta;

            DbUpsert::run(
                $db,
                table:        'ip_reputation',
                data:         ['ip' => $ip, 'score' => $new],
                conflictCols: ['ip'],
                updateCols:   ['score'],
                updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
            );

            if ($ownTransaction) {
                $db->commit();
            }

            return $new;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Increase an IP's score (worse reputation).
     *
     * @param int $points Positive number of points to add (>= 1).
     */
    public function penalize(string $ip, int $points = 1): int
    {
        if ($points < 1) {
            throw new \InvalidArgumentException('Points must be at least 1.');
        }

        return $this->adjust($ip, $points);
    }

    /**
     * Decrease an IP's score (better reputation).
     *
     * @param int $points Positive number of points to subtract (>= 1).
     */
    public function reward(string $ip, int $points = 1): int
    {
        if ($points < 1) {
            throw new \InvalidArgumentException('Points must be at least 1.');
        }

        return $this->adjust($ip, -$points);
    }

    /**
     * Current score for an IP (0 if unknown).
     */
    public function score(string $ip): int
    {
        return $this->rawScore($this->validateIp($ip));
    }

    /**
     * Whether an IP's score is at or above a threshold.
     */
    public function isBad(string $ip, int $threshold): bool
    {
        return $this->score($ip) >= $threshold;
    }

    /**
     * Worst-scoring IPs, highest first.
     *
     * @param  int $limit Maximum rows (>= 1).
     * @return array<int,array{ip:string,score:int}>
     */
    public function worst(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            'SELECT ip, score FROM ip_reputation ORDER BY score DESC, ip ASC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['ip' => (string)$row['ip'], 'score' => (int)$row['score']];
        }

        return $out;
    }

    /**
     * Reset an IP's score to zero (keeps the row). No-op if absent.
     */
    public function reset(string $ip): void
    {
        $ip   = $this->validateIp($ip);
        $stmt = $this->db()->prepare('UPDATE ip_reputation SET score = 0, updated_at = CURRENT_TIMESTAMP WHERE ip = ?');
        $stmt->execute([$ip]);
    }

    /**
     * Remove an IP's row entirely. No-op if absent.
     */
    public function remove(string $ip): void
    {
        $ip   = $this->validateIp($ip);
        $stmt = $this->db()->prepare('DELETE FROM ip_reputation WHERE ip = ?');
        $stmt->execute([$ip]);
    }

    /**
     * Delete rows scoring below a threshold (decay housekeeping). Returns count removed.
     */
    public function purgeBelow(int $threshold): int
    {
        $stmt = $this->db()->prepare('DELETE FROM ip_reputation WHERE score < ?');
        $stmt->execute([$threshold]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function rawScore(string $ip): int
    {
        $stmt = $this->db()->prepare('SELECT score FROM ip_reputation WHERE ip = ?');
        $stmt->execute([$ip]);
        $score = $stmt->fetchColumn();

        return $score === false ? 0 : (int)$score;
    }

    private function validateIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            throw new \InvalidArgumentException('IP must not be empty.');
        }

        return $ip;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
