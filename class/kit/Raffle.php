<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Raffle — ticket-based prize draw with weighted winner selection.
 *
 * Collects entries (tickets) per participant for a named raffle, then draws a
 * number of distinct winners with probability proportional to ticket count.
 * Distinct from `WeightedPicker` (FT277, a stateless weighted pick over a
 * configured pool): this accumulates real participant entries and draws
 * *distinct* winners from them.
 *
 * `draw()` is random; pass a `$seed` for a deterministic, reproducible draw
 * (audited draws, tests).
 *
 * ## Usage
 *
 * ```php
 * $r = new Raffle($pdo);
 *
 * $r->enter('summer', 'alice', tickets: 3);
 * $r->enter('summer', 'bob');
 * $r->enter('summer', 'carol', tickets: 2);
 *
 * $r->entryCount('summer');        // 6 tickets
 * $r->ticketsFor('summer', 'alice'); // 3
 * $winners = $r->draw('summer', 2); // 2 distinct participants, ticket-weighted
 * $r->draw('summer', 1, seed: 42);  // deterministic
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE raffle_entries (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     raffle      VARCHAR(150) NOT NULL,
 *     participant VARCHAR(190) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Raffle
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add tickets for a participant in a raffle. More tickets = higher chance.
     *
     * @param  string $raffle      Raffle name.
     * @param  string $participant Participant identifier.
     * @param  int    $tickets     Number of tickets to add (>= 1).
     * @throws \InvalidArgumentException on empty names or tickets < 1.
     */
    public function enter(string $raffle, string $participant, int $tickets = 1): void
    {
        $raffle      = $this->validate($raffle, 'Raffle');
        $participant = $this->validate($participant, 'Participant');
        if ($tickets < 1) {
            throw new \InvalidArgumentException('Tickets must be at least 1.');
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare('INSERT INTO raffle_entries (raffle, participant) VALUES (?, ?)');
            for ($i = 0; $i < $tickets; $i++) {
                $stmt->execute([$raffle, $participant]);
            }
            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Total tickets entered in a raffle.
     */
    public function entryCount(string $raffle): int
    {
        $raffle = $this->validate($raffle, 'Raffle');
        $stmt   = $this->db()->prepare('SELECT COUNT(*) FROM raffle_entries WHERE raffle = ?');
        $stmt->execute([$raffle]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Tickets held by a participant in a raffle.
     */
    public function ticketsFor(string $raffle, string $participant): int
    {
        $raffle      = $this->validate($raffle, 'Raffle');
        $participant = $this->validate($participant, 'Participant');
        $stmt        = $this->db()->prepare('SELECT COUNT(*) FROM raffle_entries WHERE raffle = ? AND participant = ?');
        $stmt->execute([$raffle, $participant]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Whether a participant has any tickets in a raffle.
     */
    public function hasEntered(string $raffle, string $participant): bool
    {
        return $this->ticketsFor($raffle, $participant) > 0;
    }

    /**
     * Distinct participants in a raffle, ascending.
     *
     * @return array<int,string>
     */
    public function participants(string $raffle): array
    {
        $raffle = $this->validate($raffle, 'Raffle');
        $stmt   = $this->db()->prepare('SELECT DISTINCT participant FROM raffle_entries WHERE raffle = ? ORDER BY participant ASC');
        $stmt->execute([$raffle]);

        return array_map(static fn ($p): string => (string)$p, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Draw distinct winners, weighted by ticket count.
     *
     * @param  string   $raffle Raffle name.
     * @param  int      $count  Number of distinct winners to draw (>= 1).
     * @param  int|null $seed   Seed for a deterministic draw, or null for random.
     * @return array<int,string> Winning participants (up to the number available).
     * @throws \InvalidArgumentException on empty raffle or count < 1.
     */
    public function draw(string $raffle, int $count = 1, ?int $seed = null): array
    {
        $raffle = $this->validate($raffle, 'Raffle');
        if ($count < 1) {
            throw new \InvalidArgumentException('Count must be at least 1.');
        }

        $stmt = $this->db()->prepare('SELECT participant FROM raffle_entries WHERE raffle = ? ORDER BY id ASC');
        $stmt->execute([$raffle]);
        /** @var array<int,string> $tickets */
        $tickets = array_map(static fn ($p): string => (string)$p, $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($tickets === []) {
            return [];
        }

        if ($seed !== null) {
            mt_srand($seed);
        }
        // Fisher–Yates shuffle of the ticket list, then take distinct
        // participants in shuffled order — more tickets ⇒ likelier to appear first.
        for ($i = count($tickets) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$tickets[$i], $tickets[$j]] = [$tickets[$j], $tickets[$i]];
        }
        if ($seed !== null) {
            mt_srand();
        }

        $winners = [];
        foreach ($tickets as $p) {
            if (!in_array($p, $winners, true)) {
                $winners[] = $p;
                if (count($winners) === $count) {
                    break;
                }
            }
        }

        return $winners;
    }

    /**
     * Remove all entries for a raffle. Returns the number of tickets removed.
     */
    public function clear(string $raffle): int
    {
        $raffle = $this->validate($raffle, 'Raffle');
        $stmt   = $this->db()->prepare('DELETE FROM raffle_entries WHERE raffle = ?');
        $stmt->execute([$raffle]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

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
