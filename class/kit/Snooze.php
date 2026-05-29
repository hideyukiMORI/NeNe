<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * Snooze — temporarily hide an item until a wake-up time.
 *
 * "Snooze this until tomorrow 9am": hides a notification, task, ticket, or any
 * keyed item from an owner's active view until `wake_at`, after which it
 * resurfaces via {@see Snooze::due()}. One snooze per `(owner, item)`;
 * re-snoozing updates the wake time. Distinct from `Reminder` (FT184, which
 * creates a *new* future reminder): Snooze defers an *existing* item.
 *
 * ## Usage
 *
 * ```php
 * $s = new Snooze($pdo);
 *
 * $s->snooze('user-1', 'ticket-42', '2026-05-30 09:00:00');
 *
 * $s->isSnoozed('user-1', 'ticket-42');   // true (before wake)
 * $s->snoozed('user-1');                  // still-hidden items, soonest wake first
 * $s->due('user-1');                      // items whose wake time has passed
 *
 * $s->unsnooze('user-1', 'ticket-42');    // cancel early
 * $s->clearWoken('user-1');               // drop woken rows after resurfacing
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE snoozes (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     owner      VARCHAR(190) NOT NULL,
 *     item       VARCHAR(190) NOT NULL,
 *     wake_at    DATETIME     NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (owner, item)
 * );
 * ```
 */
final class Snooze
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Snooze an item until a wake time. Idempotent per (owner, item) — re-snoozing
     * replaces the wake time.
     *
     * @param  string $owner Owner key (user/session).
     * @param  string $item  Item identifier.
     * @param  string $until Wake time ('Y-m-d H:i:s' or parseable).
     * @throws \InvalidArgumentException on empty owner/item or bad time.
     */
    public function snooze(string $owner, string $item, string $until): void
    {
        $owner = $this->validate($owner, 'Owner');
        $item  = $this->validate($item, 'Item');
        $wake  = $this->ts($until);

        DbUpsert::run(
            $this->db(),
            table:        'snoozes',
            data:         ['owner' => $owner, 'item' => $item, 'wake_at' => $wake],
            conflictCols: ['owner', 'item'],
            updateCols:   ['wake_at'],
        );
    }

    /**
     * Whether an item is currently snoozed (wake time still in the future).
     *
     * @param string      $owner Owner key.
     * @param string      $item  Item id.
     * @param string|null $asOf  Reference time; defaults to now.
     */
    public function isSnoozed(string $owner, string $item, ?string $asOf = null): bool
    {
        $owner = $this->validate($owner, 'Owner');
        $item  = $this->validate($item, 'Item');

        $stmt = $this->db()->prepare(
            'SELECT 1 FROM snoozes WHERE owner = ? AND item = ? AND wake_at > ? LIMIT 1'
        );
        $stmt->execute([$owner, $item, $this->ts($asOf ?? 'now')]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Wake time for an item, or null if not snoozed.
     */
    public function wakeAt(string $owner, string $item): ?string
    {
        $owner = $this->validate($owner, 'Owner');
        $item  = $this->validate($item, 'Item');

        $stmt = $this->db()->prepare('SELECT wake_at FROM snoozes WHERE owner = ? AND item = ?');
        $stmt->execute([$owner, $item]);
        $w = $stmt->fetchColumn();

        return $w === false ? null : (string)$w;
    }

    /**
     * Still-snoozed items for an owner (wake time in the future), soonest first.
     *
     * @param  string      $owner Owner key.
     * @param  string|null $asOf  Reference time; defaults to now.
     * @return array<int,array{item:string,wake_at:string}>
     */
    public function snoozed(string $owner, ?string $asOf = null): array
    {
        return $this->query($owner, 'wake_at > ?', $asOf, 'ASC');
    }

    /**
     * Items whose wake time has passed (ready to resurface), oldest wake first.
     *
     * @param  string      $owner Owner key.
     * @param  string|null $asOf  Reference time; defaults to now.
     * @return array<int,array{item:string,wake_at:string}>
     */
    public function due(string $owner, ?string $asOf = null): array
    {
        return $this->query($owner, 'wake_at <= ?', $asOf, 'ASC');
    }

    /**
     * Cancel a snooze. No-op if absent.
     */
    public function unsnooze(string $owner, string $item): void
    {
        $owner = $this->validate($owner, 'Owner');
        $item  = $this->validate($item, 'Item');
        $stmt  = $this->db()->prepare('DELETE FROM snoozes WHERE owner = ? AND item = ?');
        $stmt->execute([$owner, $item]);
    }

    /**
     * Delete woken rows (wake time passed) for an owner, after resurfacing them.
     *
     * @param  string      $owner Owner key.
     * @param  string|null $asOf  Reference time; defaults to now.
     * @return int                Number of rows removed.
     */
    public function clearWoken(string $owner, ?string $asOf = null): int
    {
        $owner = $this->validate($owner, 'Owner');
        $stmt  = $this->db()->prepare('DELETE FROM snoozes WHERE owner = ? AND wake_at <= ?');
        $stmt->execute([$owner, $this->ts($asOf ?? 'now')]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<int,array{item:string,wake_at:string}>
     */
    private function query(string $owner, string $cond, ?string $asOf, string $dir): array
    {
        $owner = $this->validate($owner, 'Owner');
        $stmt  = $this->db()->prepare(
            "SELECT item, wake_at FROM snoozes WHERE owner = ? AND {$cond} ORDER BY wake_at {$dir}"
        );
        $stmt->execute([$owner, $this->ts($asOf ?? 'now')]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['item' => (string)$row['item'], 'wake_at' => (string)$row['wake_at']];
        }

        return $out;
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function ts(string $value): string
    {
        $epoch = strtotime($value);
        if ($epoch === false) {
            throw new \InvalidArgumentException("Invalid time: {$value}");
        }

        return date('Y-m-d H:i:s', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
