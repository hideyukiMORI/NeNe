<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * FunnelStep — conversion-funnel step completion tracking.
 *
 * Records when a subject (user, session, visitor) reaches a named step in a
 * funnel (`signup → verify → onboard → activate`) so drop-off and
 * step-to-step conversion can be computed. Each step carries an order for
 * sorting the funnel visualisation.
 *
 * Recording a step is idempotent per `(funnel, subject, step)` — reaching the
 * same step twice does not double-count.
 *
 * ## Usage
 *
 * ```php
 * $f = new FunnelStep($pdo);
 *
 * $f->reach('signup', 'user-1', 'visit',    1);
 * $f->reach('signup', 'user-1', 'register', 2);
 * $f->reach('signup', 'user-2', 'visit',    1);
 *
 * $f->hasReached('signup', 'user-1', 'register'); // true
 * $f->reachedSteps('signup', 'user-1');           // ['visit','register']
 * $f->counts('signup');                           // [['step'=>'visit','order'=>1,'subjects'=>2], ...]
 * $f->conversionRate('signup', 'visit', 'register'); // 0.5
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE funnel_steps (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     funnel     VARCHAR(100) NOT NULL,
 *     subject    VARCHAR(190) NOT NULL,
 *     step       VARCHAR(100) NOT NULL,
 *     step_order INTEGER      NOT NULL DEFAULT 0,
 *     reached_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (funnel, subject, step)
 * );
 * ```
 */
final class FunnelStep
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record that a subject reached a funnel step. Idempotent per subject+step.
     *
     * @param  string      $funnel Funnel name.
     * @param  string      $subject Subject id (user/session/visitor).
     * @param  string      $step    Step name.
     * @param  int         $order   Sort order of the step within the funnel.
     * @param  string|null $asOf    Reach time; defaults to now.
     * @throws \InvalidArgumentException on empty funnel/subject/step.
     */
    public function reach(string $funnel, string $subject, string $step, int $order = 0, ?string $asOf = null): void
    {
        $funnel  = $this->validate($funnel, 'Funnel');
        $subject = $this->validate($subject, 'Subject');
        $step    = $this->validate($step, 'Step');

        DbUpsert::run(
            $this->db(),
            table:        'funnel_steps',
            data:         ['funnel' => $funnel, 'subject' => $subject, 'step' => $step, 'step_order' => $order, 'reached_at' => $this->ts($asOf)],
            conflictCols: ['funnel', 'subject', 'step'],
            updateCols:   ['step_order'],
        );
    }

    /**
     * Whether a subject reached a step.
     */
    public function hasReached(string $funnel, string $subject, string $step): bool
    {
        $funnel  = $this->validate($funnel, 'Funnel');
        $subject = $this->validate($subject, 'Subject');
        $step    = $this->validate($step, 'Step');

        $stmt = $this->db()->prepare(
            'SELECT 1 FROM funnel_steps WHERE funnel = ? AND subject = ? AND step = ? LIMIT 1'
        );
        $stmt->execute([$funnel, $subject, $step]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Steps a subject reached, in funnel order.
     *
     * @return array<int,string>
     */
    public function reachedSteps(string $funnel, string $subject): array
    {
        $funnel  = $this->validate($funnel, 'Funnel');
        $subject = $this->validate($subject, 'Subject');

        $stmt = $this->db()->prepare(
            'SELECT step FROM funnel_steps WHERE funnel = ? AND subject = ? ORDER BY step_order ASC, reached_at ASC, id ASC'
        );
        $stmt->execute([$funnel, $subject]);

        return array_map(static fn ($s): string => (string)$s, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Distinct-subject counts per step, in funnel order — the funnel chart data.
     *
     * @return array<int,array{step:string,order:int,subjects:int}>
     */
    public function counts(string $funnel): array
    {
        $funnel = $this->validate($funnel, 'Funnel');

        $stmt = $this->db()->prepare(
            'SELECT step, MIN(step_order) AS ord, COUNT(DISTINCT subject) AS subjects
             FROM funnel_steps WHERE funnel = ?
             GROUP BY step ORDER BY ord ASC, step ASC'
        );
        $stmt->execute([$funnel]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['step' => (string)$row['step'], 'order' => (int)$row['ord'], 'subjects' => (int)$row['subjects']];
        }

        return $out;
    }

    /**
     * Step-to-step conversion rate: of subjects who reached $fromStep, the
     * fraction that also reached $toStep. Returns 0.0 if none reached $fromStep.
     *
     * @return float Rounded to 4 decimal places.
     */
    public function conversionRate(string $funnel, string $fromStep, string $toStep): float
    {
        $funnel   = $this->validate($funnel, 'Funnel');
        $fromStep = $this->validate($fromStep, 'From step');
        $toStep   = $this->validate($toStep, 'To step');

        $denomStmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT subject) FROM funnel_steps WHERE funnel = ? AND step = ?'
        );
        $denomStmt->execute([$funnel, $fromStep]);
        $denom = (int)$denomStmt->fetchColumn();
        if ($denom === 0) {
            return 0.0;
        }

        $numStmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT subject) FROM funnel_steps
             WHERE funnel = ? AND step = ?
             AND subject IN (SELECT subject FROM funnel_steps WHERE funnel = ? AND step = ?)'
        );
        $numStmt->execute([$funnel, $toStep, $funnel, $fromStep]);
        $num = (int)$numStmt->fetchColumn();

        return round($num / $denom, 4);
    }

    /**
     * Delete funnel records older than $days. Returns rows removed.
     */
    public function purgeOlderThan(int $days, ?string $asOf = null): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('Days must not be negative.');
        }
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }
        $cutoff = date('Y-m-d H:i:s', $epoch - $days * 86400);

        $stmt = $this->db()->prepare('DELETE FROM funnel_steps WHERE reached_at < ?');
        $stmt->execute([$cutoff]);

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
