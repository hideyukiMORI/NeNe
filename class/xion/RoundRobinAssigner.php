<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * RoundRobinAssigner — fair rotating assignment across a named pool.
 *
 * Hands out members of a pool in rotation so work is distributed evenly:
 * support tickets to agents, leads to reps, jobs to workers. Each pool keeps
 * an ordered member list and a persistent cursor, so the rotation survives
 * across requests and processes.
 *
 * `next()` advances the cursor atomically (read + pick + advance inside a
 * transaction), so concurrent callers never receive the same slot twice in a
 * row from one cycle.
 *
 * ## Usage
 *
 * ```php
 * $rr = new RoundRobinAssigner($pdo);
 *
 * $rr->setMembers('support', ['alice', 'bob', 'carol']);
 *
 * $rr->next('support'); // 'alice'
 * $rr->next('support'); // 'bob'
 * $rr->next('support'); // 'carol'
 * $rr->next('support'); // 'alice'  (wraps)
 *
 * $rr->peek('support');             // next without advancing
 * $rr->addMember('support', 'dave');
 * $rr->removeMember('support', 'bob');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE round_robin_pools (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     pool       VARCHAR(100) NOT NULL,
 *     members    TEXT         NOT NULL DEFAULT '[]',
 *     cursor     INTEGER      NOT NULL DEFAULT 0,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (pool)
 * );
 * ```
 */
final class RoundRobinAssigner
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set the full member list for a pool (resets the cursor to the start).
     *
     * Duplicate and blank members are dropped; order is preserved.
     *
     * @param  string             $pool    Pool name.
     * @param  array<int,string>  $members Ordered member identifiers.
     * @throws \InvalidArgumentException on empty pool name.
     */
    public function setMembers(string $pool, array $members): void
    {
        $pool  = $this->validatePool($pool);
        $clean = $this->cleanMembers($members);

        DbUpsert::run(
            $this->db(),
            table:        'round_robin_pools',
            data:         ['pool' => $pool, 'members' => $this->encode($clean), 'cursor' => 0],
            conflictCols: ['pool'],
            updateCols:   ['members', 'cursor'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Return the current member list for a pool.
     *
     * @return array<int,string>
     */
    public function members(string $pool): array
    {
        $row = $this->fetch($this->validatePool($pool));

        return $row === null ? [] : $this->decode((string)$row['members']);
    }

    /**
     * Return the next member and advance the cursor (atomic).
     *
     * @param  string $pool Pool name.
     * @return string|null  The assigned member, or null if the pool is empty/unknown.
     */
    public function next(string $pool): ?string
    {
        $pool           = $this->validatePool($pool);
        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $row = $this->fetch($pool);
            if ($row === null) {
                if ($ownTransaction) {
                    $db->commit();
                }

                return null;
            }

            $members = $this->decode((string)$row['members']);
            $count   = count($members);
            if ($count === 0) {
                if ($ownTransaction) {
                    $db->commit();
                }

                return null;
            }

            $cursor = (int)$row['cursor'] % $count;
            $member = $members[$cursor];
            $next   = ($cursor + 1) % $count;

            $stmt = $db->prepare('UPDATE round_robin_pools SET cursor = ? WHERE pool = ?');
            $stmt->execute([$next, $pool]);

            if ($ownTransaction) {
                $db->commit();
            }

            return $member;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Return the member that `next()` would assign, without advancing.
     */
    public function peek(string $pool): ?string
    {
        $row = $this->fetch($this->validatePool($pool));
        if ($row === null) {
            return null;
        }
        $members = $this->decode((string)$row['members']);
        $count   = count($members);
        if ($count === 0) {
            return null;
        }

        return $members[(int)$row['cursor'] % $count];
    }

    /**
     * Append a member to the pool if not already present. Creates the pool if new.
     */
    public function addMember(string $pool, string $member): void
    {
        $pool   = $this->validatePool($pool);
        $member = trim($member);
        if ($member === '') {
            throw new \InvalidArgumentException('Member must not be empty.');
        }

        $members = $this->members($pool);
        if (!in_array($member, $members, true)) {
            $members[] = $member;
            $this->persistMembers($pool, $members);
        }
    }

    /**
     * Remove a member from the pool. No-op if absent.
     */
    public function removeMember(string $pool, string $member): void
    {
        $pool   = $this->validatePool($pool);
        $member = trim($member);

        $members = $this->members($pool);
        $filtered = array_values(array_filter($members, static fn (string $m): bool => $m !== $member));
        if (count($filtered) !== count($members)) {
            $this->persistMembers($pool, $filtered);
        }
    }

    /**
     * Reset the rotation cursor to the start. No-op if the pool is unknown.
     */
    public function reset(string $pool): void
    {
        $pool = $this->validatePool($pool);
        $stmt = $this->db()->prepare('UPDATE round_robin_pools SET cursor = 0 WHERE pool = ?');
        $stmt->execute([$pool]);
    }

    /**
     * Delete a pool entirely. No-op if absent.
     */
    public function remove(string $pool): void
    {
        $pool = $this->validatePool($pool);
        $stmt = $this->db()->prepare('DELETE FROM round_robin_pools WHERE pool = ?');
        $stmt->execute([$pool]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * Persist a member list, clamping the cursor into range (preserves position).
     *
     * @param array<int,string> $members
     */
    private function persistMembers(string $pool, array $members): void
    {
        $row    = $this->fetch($pool);
        $cursor = $row === null ? 0 : (int)$row['cursor'];
        $count  = count($members);
        $cursor = $count === 0 ? 0 : $cursor % $count;

        DbUpsert::run(
            $this->db(),
            table:        'round_robin_pools',
            data:         ['pool' => $pool, 'members' => $this->encode($members), 'cursor' => $cursor],
            conflictCols: ['pool'],
            updateCols:   ['members', 'cursor'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetch(string $pool): ?array
    {
        $stmt = $this->db()->prepare('SELECT members, cursor FROM round_robin_pools WHERE pool = ?');
        $stmt->execute([$pool]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param  array<int,string> $members
     * @return array<int,string>
     */
    private function cleanMembers(array $members): array
    {
        $out = [];
        foreach ($members as $m) {
            $m = trim($m);
            if ($m !== '' && !in_array($m, $out, true)) {
                $out[] = $m;
            }
        }

        return $out;
    }

    /**
     * @param array<int,string> $members
     */
    private function encode(array $members): string
    {
        return json_encode(array_values($members), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int,string>
     */
    private function decode(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $m) {
            $out[] = (string)$m;
        }

        return $out;
    }

    private function validatePool(string $pool): string
    {
        $pool = trim($pool);
        if ($pool === '') {
            throw new \InvalidArgumentException('Pool must not be empty.');
        }

        return $pool;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
