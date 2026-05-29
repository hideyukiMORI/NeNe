<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Tournament — single-elimination entrant tracking with match recording.
 *
 * Registers entrants and records match results; the loser of each match is
 * eliminated, and when a single entrant remains it is the champion. The caller
 * pairs entrants per round (this helper does not auto-generate the bracket),
 * which keeps it flexible for byes, seeding, and manual scheduling. Distinct
 * from `Leaderboard` (FT, ranked scores) and `ScoreBoard`: this is knockout
 * progression.
 *
 * ## Usage
 *
 * ```php
 * $t = new Tournament($pdo);
 *
 * foreach (['alice','bob','carol','dave'] as $p) { $t->register('cup', $p); }
 *
 * $t->recordMatch('cup', 1, 'alice', 'bob',   'alice'); // bob eliminated
 * $t->recordMatch('cup', 1, 'carol', 'dave',  'carol'); // dave eliminated
 * $t->remaining('cup');                                  // ['alice','carol']
 * $t->recordMatch('cup', 2, 'alice', 'carol', 'alice'); // carol eliminated
 * $t->champion('cup');                                   // 'alice'
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE tournament_entrants (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     tournament VARCHAR(150) NOT NULL,
 *     player     VARCHAR(190) NOT NULL,
 *     eliminated INTEGER      NOT NULL DEFAULT 0,
 *     UNIQUE (tournament, player)
 * );
 * CREATE TABLE tournament_matches (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     tournament VARCHAR(150) NOT NULL,
 *     round      INTEGER      NOT NULL,
 *     player_a   VARCHAR(190) NOT NULL,
 *     player_b   VARCHAR(190) NOT NULL,
 *     winner     VARCHAR(190) NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Tournament
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register an entrant (before/while the tournament runs). Idempotent.
     *
     * @throws \InvalidArgumentException on empty names.
     */
    public function register(string $tournament, string $player): void
    {
        $tournament = $this->validate($tournament, 'Tournament');
        $player     = $this->validate($player, 'Player');

        if ($this->isRegistered($tournament, $player)) {
            return;
        }
        $stmt = $this->db()->prepare('INSERT INTO tournament_entrants (tournament, player) VALUES (?, ?)');
        $stmt->execute([$tournament, $player]);
    }

    /**
     * Record a match result; the loser is eliminated.
     *
     * @param  string $tournament Tournament name.
     * @param  int    $round      Round number (informational; >= 1).
     * @param  string $playerA    First entrant.
     * @param  string $playerB    Second entrant.
     * @param  string $winner     The winner (must equal $playerA or $playerB).
     * @return int                New match id.
     * @throws \InvalidArgumentException on bad round, same players, unregistered
     *                                   or already-eliminated entrant, or bad winner.
     */
    public function recordMatch(string $tournament, int $round, string $playerA, string $playerB, string $winner): int
    {
        $tournament = $this->validate($tournament, 'Tournament');
        $playerA    = $this->validate($playerA, 'Player A');
        $playerB    = $this->validate($playerB, 'Player B');
        if ($round < 1) {
            throw new \InvalidArgumentException('Round must be at least 1.');
        }
        if ($playerA === $playerB) {
            throw new \InvalidArgumentException('A match needs two different players.');
        }
        if ($winner !== $playerA && $winner !== $playerB) {
            throw new \InvalidArgumentException('Winner must be one of the two players.');
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            foreach ([$playerA, $playerB] as $p) {
                $state = $this->entrantState($tournament, $p);
                if ($state === null) {
                    throw new \InvalidArgumentException("Player not registered: {$p}");
                }
                if ($state === 1) {
                    throw new \InvalidArgumentException("Player already eliminated: {$p}");
                }
            }

            $loser = $winner === $playerA ? $playerB : $playerA;
            $db->prepare('UPDATE tournament_entrants SET eliminated = 1 WHERE tournament = ? AND player = ?')
               ->execute([$tournament, $loser]);

            $ins = $db->prepare('INSERT INTO tournament_matches (tournament, round, player_a, player_b, winner) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$tournament, $round, $playerA, $playerB, $winner]);
            $id = (int)$db->lastInsertId();

            if ($ownTransaction) {
                $db->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * All entrants (registration order).
     *
     * @return array<int,string>
     */
    public function entrants(string $tournament): array
    {
        return $this->players($tournament, null);
    }

    /**
     * Entrants still in the tournament (not eliminated), registration order.
     *
     * @return array<int,string>
     */
    public function remaining(string $tournament): array
    {
        return $this->players($tournament, 0);
    }

    /**
     * Whether a player has been eliminated.
     */
    public function isEliminated(string $tournament, string $player): bool
    {
        return $this->entrantState($this->validate($tournament, 'Tournament'), $this->validate($player, 'Player')) === 1;
    }

    /**
     * The champion: the single remaining entrant once at least one match has
     * been played; null while more than one remains (or none played).
     */
    public function champion(string $tournament): ?string
    {
        $tournament = $this->validate($tournament, 'Tournament');
        if ($this->matchCount($tournament) === 0) {
            return null;
        }
        $remaining = $this->remaining($tournament);

        return count($remaining) === 1 ? $remaining[0] : null;
    }

    /**
     * Recorded matches (optionally for one round), in play order.
     *
     * @return array<int,array{round:int,player_a:string,player_b:string,winner:string}>
     */
    public function matches(string $tournament, ?int $round = null): array
    {
        $tournament = $this->validate($tournament, 'Tournament');
        if ($round === null) {
            $stmt = $this->db()->prepare('SELECT round, player_a, player_b, winner FROM tournament_matches WHERE tournament = ? ORDER BY id ASC');
            $stmt->execute([$tournament]);
        } else {
            $stmt = $this->db()->prepare('SELECT round, player_a, player_b, winner FROM tournament_matches WHERE tournament = ? AND round = ? ORDER BY id ASC');
            $stmt->execute([$tournament, $round]);
        }

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'round'    => (int)$r['round'],
                'player_a' => (string)$r['player_a'],
                'player_b' => (string)$r['player_b'],
                'winner'   => (string)$r['winner'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<int,string>
     */
    private function players(string $tournament, ?int $eliminated): array
    {
        $tournament = $this->validate($tournament, 'Tournament');
        if ($eliminated === null) {
            $stmt = $this->db()->prepare('SELECT player FROM tournament_entrants WHERE tournament = ? ORDER BY id ASC');
            $stmt->execute([$tournament]);
        } else {
            $stmt = $this->db()->prepare('SELECT player FROM tournament_entrants WHERE tournament = ? AND eliminated = ? ORDER BY id ASC');
            $stmt->execute([$tournament, $eliminated]);
        }

        return array_map(static fn ($p): string => (string)$p, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function isRegistered(string $tournament, string $player): bool
    {
        return $this->entrantState($tournament, $player) !== null;
    }

    /**
     * @return int|null 0 = active, 1 = eliminated, null = not registered.
     */
    private function entrantState(string $tournament, string $player): ?int
    {
        $stmt = $this->db()->prepare('SELECT eliminated FROM tournament_entrants WHERE tournament = ? AND player = ?');
        $stmt->execute([$tournament, $player]);
        $e = $stmt->fetchColumn();

        return $e === false ? null : (int)$e;
    }

    private function matchCount(string $tournament): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM tournament_matches WHERE tournament = ?');
        $stmt->execute([$tournament]);

        return (int)$stmt->fetchColumn();
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
