<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Dispute — transaction dispute / chargeback workflow.
 *
 * Tracks a payment dispute through its lifecycle: open → under_review →
 * won|lost. Evidence can be attached while the case is unresolved, and the
 * total amount currently at risk (open + under_review) can be summed for
 * reporting. Distinct from `ContentReport`/`ContentFlag` (moderation queues)
 * and generic ticketing: this is money-bearing, with a guarded state machine
 * and a final win/loss outcome.
 *
 * ## Usage
 *
 * ```php
 * $d = new Dispute($pdo);
 *
 * $id = $d->open('txn_8842', 'item not received', 1500); // open, 1500 cents
 * $d->review($id);                                        // → under_review
 * $d->addEvidence($id, 'tracking shows delivered');
 * $d->resolve($id, won: true);                            // → won
 *
 * $d->status($id);          // 'won'
 * $d->byStatus('open');     // [ ...rows... ]
 * $d->amountAtRisk();       // sum of open + under_review amounts (cents)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE disputes (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reference    VARCHAR(190) NOT NULL,
 *     reason       VARCHAR(255) NOT NULL DEFAULT '',
 *     amount_cents INTEGER      NOT NULL DEFAULT 0,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     evidence     TEXT         NOT NULL DEFAULT '',
 *     opened_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     resolved_at  DATETIME     NULL
 * );
 * ```
 */
final class Dispute
{
    private const OPEN   = 'open';
    private const REVIEW = 'under_review';
    private const WON    = 'won';
    private const LOST   = 'lost';

    /** Statuses where the case is not yet resolved (money still at risk). */
    private const UNRESOLVED = [self::OPEN, self::REVIEW];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open a new dispute against a transaction reference.
     *
     * @param  string $reference   Transaction / order identifier.
     * @param  string $reason      Free-text reason.
     * @param  int    $amountCents Disputed amount in integer cents (>= 0).
     * @return int                 New dispute id.
     * @throws \InvalidArgumentException on empty reference or negative amount.
     */
    public function open(string $reference, string $reason, int $amountCents): int
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new \InvalidArgumentException('Reference must not be empty.');
        }
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('Amount must not be negative.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO disputes (reference, reason, amount_cents, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$reference, trim($reason), $amountCents, self::OPEN]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Move an open dispute into review. Returns false if the dispute is not
     * currently open (missing or already past open).
     */
    public function review(int $id): bool
    {
        return $this->transition($id, self::REVIEW, [self::OPEN], false);
    }

    /**
     * Append a line of evidence. Allowed only while the case is unresolved.
     *
     * @throws \InvalidArgumentException on empty text.
     * @return bool True if appended; false if the dispute is missing or resolved.
     */
    public function addEvidence(int $id, string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Evidence must not be empty.');
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $row = $this->lockRow($id);
            if ($row === null || !in_array($row['status'], self::UNRESOLVED, true)) {
                if ($ownTransaction) {
                    $db->commit();
                }

                return false;
            }

            $existing = $row['evidence'];
            $merged   = $existing === '' ? $text : $existing . "\n" . $text;
            $db->prepare('UPDATE disputes SET evidence = ? WHERE id = ?')->execute([$merged, $id]);

            if ($ownTransaction) {
                $db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resolve the dispute as won or lost. Allowed from open or under_review.
     *
     * @param  int  $id  Dispute id.
     * @param  bool $won True → won, false → lost.
     * @return bool      True if resolved; false if missing or already resolved.
     */
    public function resolve(int $id, bool $won): bool
    {
        return $this->transition($id, $won ? self::WON : self::LOST, self::UNRESOLVED, true);
    }

    /**
     * Current status, or null if the dispute does not exist.
     */
    public function status(int $id): ?string
    {
        $row = $this->get($id);

        return $row === null ? null : $row['status'];
    }

    /**
     * Full dispute row, or null.
     *
     * @return array{id:int,reference:string,reason:string,amount_cents:int,status:string,evidence:string,resolved_at:?string}|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, reference, reason, amount_cents, status, evidence, resolved_at FROM disputes WHERE id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return [
            'id'           => (int)$r['id'],
            'reference'    => (string)$r['reference'],
            'reason'       => (string)$r['reason'],
            'amount_cents' => (int)$r['amount_cents'],
            'status'       => (string)$r['status'],
            'evidence'     => (string)$r['evidence'],
            'resolved_at'  => $r['resolved_at'] === null ? null : (string)$r['resolved_at'],
        ];
    }

    /**
     * Disputes with the given status, newest first.
     *
     * @return array<int,array{id:int,reference:string,reason:string,amount_cents:int,status:string}>
     */
    public function byStatus(string $status): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, reference, reason, amount_cents, status FROM disputes WHERE status = ? ORDER BY id DESC'
        );
        $stmt->execute([$status]);

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'reference'    => (string)$r['reference'],
                'reason'       => (string)$r['reason'],
                'amount_cents' => (int)$r['amount_cents'],
                'status'       => (string)$r['status'],
            ];
        }

        return $out;
    }

    /**
     * Total amount (cents) currently at risk: sum of open + under_review.
     */
    public function amountAtRisk(): int
    {
        $place = implode(', ', array_fill(0, count(self::UNRESOLVED), '?'));
        $stmt  = $this->db()->prepare(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM disputes WHERE status IN ({$place})"
        );
        $stmt->execute(self::UNRESOLVED);

        return (int)$stmt->fetchColumn();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * Guarded status transition.
     *
     * @param array<int,string> $from         Allowed source statuses.
     * @param bool              $stampResolve Whether to set resolved_at = now.
     */
    private function transition(int $id, string $to, array $from, bool $stampResolve): bool
    {
        $place = implode(', ', array_fill(0, count($from), '?'));
        $sql   = $stampResolve
            ? "UPDATE disputes SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ({$place})"
            : "UPDATE disputes SET status = ? WHERE id = ? AND status IN ({$place})";

        $stmt = $this->db()->prepare($sql);
        $stmt->execute([$to, $id, ...$from]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Read the row for an in-transaction update.
     *
     * @return array{status:string,evidence:string}|null
     */
    private function lockRow(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT status, evidence FROM disputes WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return ['status' => (string)$r['status'], 'evidence' => (string)$r['evidence']];
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
