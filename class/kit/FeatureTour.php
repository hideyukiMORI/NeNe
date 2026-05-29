<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * FeatureTour — per-user state for one-time UI tours / coachmarks.
 *
 * Tracks whether each user has seen, advanced through, completed, or dismissed
 * a guided product tour, so the front-end shows each tour only when
 * appropriate. A user with no record for a tour is "pristine" and should be
 * shown the tour; once any interaction is recorded, auto-display stops.
 *
 * `resetAll()` clears a tour for everyone — useful when the tour content
 * changes and should be re-shown.
 *
 * ## Usage
 *
 * ```php
 * $ft = new FeatureTour($pdo);
 *
 * if ($ft->shouldShow(42, 'editor-v2')) {
 *     $ft->markSeen(42, 'editor-v2');   // begin; auto-display stops
 * }
 * $ft->advance(42, 'editor-v2', 3);     // user reached step 3
 * $ft->complete(42, 'editor-v2');       // finished
 * // or: $ft->dismiss(42, 'editor-v2'); // skipped
 *
 * $ft->status(42, 'editor-v2');         // 'completed'
 * $ft->completedCount('editor-v2');     // analytics
 * $ft->resetAll('editor-v2');           // re-show to everyone after a rewrite
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE feature_tours (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    BIGINT       NOT NULL,
 *     tour       VARCHAR(100) NOT NULL,
 *     status     VARCHAR(20)  NOT NULL DEFAULT 'seen',
 *     step       INTEGER      NOT NULL DEFAULT 0,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, tour)
 * );
 * ```
 */
final class FeatureTour
{
    public const string STATUS_SEEN      = 'seen';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_DISMISSED = 'dismissed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Whether a tour should be auto-shown to a user (no prior interaction).
     */
    public function shouldShow(int $userId, string $tour): bool
    {
        return $this->row($userId, $this->validateTour($tour)) === null;
    }

    /**
     * Mark a tour as seen (begun) for a user, at an optional step. Idempotent;
     * does not regress a completed/dismissed tour back to seen.
     */
    public function markSeen(int $userId, string $tour, int $step = 0): void
    {
        $this->upsert($userId, $tour, self::STATUS_SEEN, $step, regressStatus: false);
    }

    /**
     * Record progress to a step (implies the tour is in progress / seen).
     *
     * @throws \InvalidArgumentException on negative step.
     */
    public function advance(int $userId, string $tour, int $step): void
    {
        if ($step < 0) {
            throw new \InvalidArgumentException('Step must not be negative.');
        }
        $this->upsert($userId, $tour, self::STATUS_SEEN, $step, regressStatus: false);
    }

    /**
     * Mark a tour completed for a user.
     */
    public function complete(int $userId, string $tour): void
    {
        $this->upsert($userId, $tour, self::STATUS_COMPLETED, null, regressStatus: true);
    }

    /**
     * Mark a tour dismissed (skipped) for a user.
     */
    public function dismiss(int $userId, string $tour): void
    {
        $this->upsert($userId, $tour, self::STATUS_DISMISSED, null, regressStatus: true);
    }

    /**
     * Current status for a user+tour, or null if pristine.
     */
    public function status(int $userId, string $tour): ?string
    {
        $row = $this->row($userId, $this->validateTour($tour));

        return $row === null ? null : (string)$row['status'];
    }

    /**
     * Current step for a user+tour (0 if pristine).
     */
    public function step(int $userId, string $tour): int
    {
        $row = $this->row($userId, $this->validateTour($tour));

        return $row === null ? 0 : (int)$row['step'];
    }

    /**
     * Clear one user's record for a tour (auto-display resumes). No-op if absent.
     */
    public function reset(int $userId, string $tour): void
    {
        $tour = $this->validateTour($tour);
        $stmt = $this->db()->prepare('DELETE FROM feature_tours WHERE user_id = ? AND tour = ?');
        $stmt->execute([$userId, $tour]);
    }

    /**
     * Clear a tour for every user (re-show to all). Returns rows removed.
     */
    public function resetAll(string $tour): int
    {
        $tour = $this->validateTour($tour);
        $stmt = $this->db()->prepare('DELETE FROM feature_tours WHERE tour = ?');
        $stmt->execute([$tour]);

        return $stmt->rowCount();
    }

    /**
     * Count users with a given status for a tour.
     */
    public function completedCount(string $tour): int
    {
        return $this->countByStatus($tour, self::STATUS_COMPLETED);
    }

    /**
     * Count users who dismissed a tour.
     */
    public function dismissedCount(string $tour): int
    {
        return $this->countByStatus($tour, self::STATUS_DISMISSED);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function upsert(int $userId, string $tour, string $status, ?int $step, bool $regressStatus): void
    {
        $tour = $this->validateTour($tour);
        $db   = $this->db();

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $existing = $this->row($userId, $tour);
            $newStep  = $step ?? ($existing === null ? 0 : (int)$existing['step']);

            // Do not regress a terminal status back to 'seen'.
            $newStatus = $status;
            if (!$regressStatus && $existing !== null && $status === self::STATUS_SEEN) {
                $current = (string)$existing['status'];
                if ($current === self::STATUS_COMPLETED || $current === self::STATUS_DISMISSED) {
                    $newStatus = $current;
                }
            }

            DbUpsert::run(
                $db,
                table:        'feature_tours',
                data:         ['user_id' => $userId, 'tour' => $tour, 'status' => $newStatus, 'step' => $newStep],
                conflictCols: ['user_id', 'tour'],
                updateCols:   ['status', 'step'],
                updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
            );

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

    private function countByStatus(string $tour, string $status): int
    {
        $tour = $this->validateTour($tour);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM feature_tours WHERE tour = ? AND status = ?');
        $stmt->execute([$tour, $status]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(int $userId, string $tour): ?array
    {
        $stmt = $this->db()->prepare('SELECT status, step FROM feature_tours WHERE user_id = ? AND tour = ?');
        $stmt->execute([$userId, $tour]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function validateTour(string $tour): string
    {
        $tour = trim($tour);
        if ($tour === '') {
            throw new \InvalidArgumentException('Tour must not be empty.');
        }

        return $tour;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
