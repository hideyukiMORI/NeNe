<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SubscriptionPlan — user subscription lifecycle management.
 *
 * Tracks which plan a user is subscribed to, when it expires, and
 * whether it auto-renews. Supports trial periods, plan upgrades/downgrades,
 * cancellation at end of period, and immediate cancellation.
 *
 * Amount is stored in cents to avoid float rounding. The helper does not
 * handle payment; it is the source of truth for entitlement checks.
 *
 * ## Usage
 *
 * ```php
 * $sp = new SubscriptionPlan($pdo);
 *
 * // Subscribe a user
 * $id = $sp->subscribe('user-1', 'pro', 999, '2026-07-01 00:00:00');
 *
 * // Check entitlement
 * if ($sp->isActive('user-1', '2026-06-15 12:00:00')) {
 *     // show premium features
 * }
 *
 * // Cancel at period end
 * $sp->cancelAtEnd($id);
 *
 * // Force immediate cancellation
 * $sp->cancelNow($id, '2026-06-16 10:00:00');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE subscription_plans (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     plan         VARCHAR(100) NOT NULL,
 *     amount       INTEGER      NOT NULL DEFAULT 0,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     auto_renew   TINYINT(1)   NOT NULL DEFAULT 1,
 *     starts_at    DATETIME     NOT NULL,
 *     expires_at   DATETIME     NULL,
 *     cancelled_at DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SubscriptionPlan
{
    public const STATUS_TRIAL     = 'trial';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Subscribe a user to a plan.
     *
     * @param int         $amountCents Amount in smallest currency unit.
     * @param string|null $expiresAt   Null for open-ended (manual cancellation only).
     * @param string      $status      STATUS_ACTIVE or STATUS_TRIAL.
     * @return int New subscription ID.
     * @throws \InvalidArgumentException on empty userId/plan.
     */
    public function subscribe(
        string $userId,
        string $plan,
        int $amountCents = 0,
        ?string $expiresAt = null,
        string $startsAt = '',
        string $status = self::STATUS_ACTIVE
    ): int {
        $userId = trim($userId);
        $plan   = trim($plan);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($plan === '') {
            throw new \InvalidArgumentException('plan must not be empty.');
        }

        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $starts  = $startsAt !== '' ? $startsAt : $now;
        $stmt    = $this->db()->prepare(
            'INSERT INTO subscription_plans
                 (user_id, plan, amount, status, auto_renew, starts_at, expires_at, created_at)
             VALUES (:uid, :plan, :amount, :status, 1, :starts, :expires, :now)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':plan'    => $plan,
            ':amount'  => $amountCents,
            ':status'  => $status,
            ':starts'  => $starts,
            ':expires' => $expiresAt,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a subscription by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM subscription_plans WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the current active/trial subscription for a user, or null if none.
     *
     * If multiple active subscriptions exist (e.g. overlapping plans), returns
     * the most-recently-created one.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM subscription_plans
             WHERE user_id = :uid
               AND status IN (:active, :trial)
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':uid'    => trim($userId),
            ':active' => self::STATUS_ACTIVE,
            ':trial'  => self::STATUS_TRIAL,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return true if the user has an active/trial subscription that has not expired.
     */
    public function isActive(string $userId, string $asOf = ''): bool
    {
        $now  = $asOf !== '' ? $asOf : (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM subscription_plans
             WHERE user_id = :uid
               AND status IN (:active, :trial)
               AND starts_at <= :now
               AND (expires_at IS NULL OR expires_at > :now2)'
        );
        $stmt->execute([
            ':uid'    => trim($userId),
            ':active' => self::STATUS_ACTIVE,
            ':trial'  => self::STATUS_TRIAL,
            ':now'    => $now,
            ':now2'   => $now,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Upgrade or downgrade a subscription to a new plan.
     *
     * Creates a new subscription row and cancels the old one immediately.
     *
     * @return int New subscription ID.
     */
    public function changePlan(
        int $oldId,
        string $newPlan,
        int $amountCents = 0,
        ?string $expiresAt = null
    ): int {
        $old = $this->get($oldId);
        if ($old === null) {
            throw new \RuntimeException('Subscription not found.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->cancelNow($oldId, $now);

        return $this->subscribe((string)$old['user_id'], $newPlan, $amountCents, $expiresAt);
    }

    /**
     * Set auto_renew = 0; the subscription expires at period end but stays active until then.
     *
     * @return bool True if found and updated.
     */
    public function cancelAtEnd(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE subscription_plans SET auto_renew = 0 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel immediately: set status = CANCELLED and cancelled_at.
     *
     * @return bool True if found and updated.
     */
    public function cancelNow(int $id, ?string $cancelledAt = null): bool
    {
        $at   = $cancelledAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE subscription_plans
             SET status = :status, cancelled_at = :at
             WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_CANCELLED, ':at' => $at, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark expired subscriptions (expires_at <= $asOf) as STATUS_EXPIRED.
     *
     * Returns the number of rows updated. Called by a cron job.
     */
    public function expireStale(string $asOf = ''): int
    {
        $now  = $asOf !== '' ? $asOf : (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE subscription_plans
             SET status = :expired
             WHERE status IN (:active, :trial)
               AND expires_at IS NOT NULL
               AND expires_at <= :now'
        );
        $stmt->execute([
            ':expired' => self::STATUS_EXPIRED,
            ':active'  => self::STATUS_ACTIVE,
            ':trial'   => self::STATUS_TRIAL,
            ':now'     => $now,
        ]);
        return $stmt->rowCount();
    }

    /**
     * List all subscriptions for a user (all statuses), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM subscription_plans
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
