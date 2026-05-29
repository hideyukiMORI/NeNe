<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Subscription — track recurring subscription plans per user.
 *
 * Records the lifecycle of a user's subscription: active, cancelled,
 * expired, and past-due. Supports trial periods and renewal tracking.
 *
 * Status lifecycle: `trialing` | `active` → `cancelled` | `expired` | `past_due`
 *
 * ## Usage
 *
 * ```php
 * $sub = new Subscription($pdo);
 *
 * // Subscribe a user
 * $id = $sub->subscribe('user-1', 'pro', new \DateTimeImmutable('+1 month'));
 *
 * // With trial
 * $id = $sub->subscribe('user-2', 'pro', new \DateTimeImmutable('+1 month'),
 *     new \DateTimeImmutable('+14 days'));
 *
 * // Check status
 * $sub->isActive('user-1');
 * $sub->status('user-1', 'pro');
 *
 * // Cancel
 * $sub->cancel('user-1', 'pro');
 *
 * // Renew
 * $sub->renew('user-1', 'pro', new \DateTimeImmutable('+1 month'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE subscriptions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     plan         VARCHAR(100) NOT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     trial_ends_at  DATETIME   DEFAULT NULL,
 *     current_period_ends_at DATETIME NOT NULL,
 *     cancelled_at DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, plan)
 * );
 * ```
 */
final class Subscription
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Subscribe a user to a plan.
     *
     * If an active/trialing subscription already exists for (user, plan),
     * it is replaced.
     *
     * @return int The subscription record ID.
     * @throws \InvalidArgumentException if user_id or plan is empty.
     */
    public function subscribe(
        string $userId,
        string $plan,
        \DateTimeImmutable $currentPeriodEndsAt,
        ?\DateTimeImmutable $trialEndsAt = null
    ): int {
        [$userId, $plan] = $this->normalise($userId, $plan);
        $db              = $this->db();
        $driver          = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        $status  = $trialEndsAt !== null ? 'trialing' : 'active';
        $periodStr = $currentPeriodEndsAt->format('Y-m-d H:i:s');
        $trialStr  = $trialEndsAt?->format('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO subscriptions (user_id, plan, status, trial_ends_at, current_period_ends_at, cancelled_at)
                 VALUES (:uid, :plan, :status, :trial, :period, NULL)
                 ON CONFLICT (user_id, plan)
                 DO UPDATE SET status = excluded.status,
                               trial_ends_at = excluded.trial_ends_at,
                               current_period_ends_at = excluded.current_period_ends_at,
                               cancelled_at = NULL'
            )->execute([':uid' => $userId, ':plan' => $plan, ':status' => $status,
                ':trial' => $trialStr, ':period' => $periodStr]);
        } else {
            $db->prepare(
                'INSERT INTO subscriptions (user_id, plan, status, trial_ends_at, current_period_ends_at, cancelled_at)
                 VALUES (:uid, :plan, :status, :trial, :period, NULL)
                 ON DUPLICATE KEY UPDATE status = VALUES(status),
                                         trial_ends_at = VALUES(trial_ends_at),
                                         current_period_ends_at = VALUES(current_period_ends_at),
                                         cancelled_at = NULL'
            )->execute([':uid' => $userId, ':plan' => $plan, ':status' => $status,
                ':trial' => $trialStr, ':period' => $periodStr]);
        }

        $stmt = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :uid AND plan = :plan LIMIT 1');
        $stmt->execute([':uid' => $userId, ':plan' => $plan]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cancel a subscription (sets cancelled_at; preserves access until period ends).
     *
     * @return bool True if an active/trialing subscription was found and cancelled.
     */
    public function cancel(string $userId, string $plan): bool
    {
        [$userId, $plan] = $this->normalise($userId, $plan);
        $stmt            = $this->db()->prepare(
            "UPDATE subscriptions
             SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP
             WHERE user_id = :uid AND plan = :plan AND status IN ('active', 'trialing')"
        );
        $stmt->execute([':uid' => $userId, ':plan' => $plan]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Renew a subscription (extend the current period).
     *
     * @return bool True if the subscription was found.
     */
    public function renew(string $userId, string $plan, \DateTimeImmutable $newPeriodEndsAt): bool
    {
        [$userId, $plan] = $this->normalise($userId, $plan);
        $stmt            = $this->db()->prepare(
            "UPDATE subscriptions
             SET current_period_ends_at = :period, status = 'active', cancelled_at = NULL
             WHERE user_id = :uid AND plan = :plan"
        );
        $stmt->execute([
            ':period' => $newPeriodEndsAt->format('Y-m-d H:i:s'),
            ':uid'    => $userId,
            ':plan'   => $plan,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a subscription as past-due.
     */
    public function markPastDue(string $userId, string $plan): bool
    {
        [$userId, $plan] = $this->normalise($userId, $plan);
        $stmt            = $this->db()->prepare(
            "UPDATE subscriptions SET status = 'past_due'
             WHERE user_id = :uid AND plan = :plan AND status = 'active'"
        );
        $stmt->execute([':uid' => $userId, ':plan' => $plan]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the subscription record for a user and plan.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $userId, string $plan): ?array
    {
        [$userId, $plan] = $this->normalise($userId, $plan);
        $stmt            = $this->db()->prepare(
            'SELECT id, user_id, plan, status, trial_ends_at, current_period_ends_at,
                    cancelled_at, created_at
             FROM subscriptions WHERE user_id = :uid AND plan = :plan LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':plan' => $plan]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the current status of a subscription.
     *
     * @return 'trialing'|'active'|'cancelled'|'expired'|'past_due'|null
     */
    public function status(string $userId, string $plan): ?string
    {
        $row = $this->find($userId, $plan);
        if ($row === null) {
            return null;
        }
        // Auto-expire if period has passed
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        if (in_array($row['status'], ['active', 'trialing'], true) && $row['current_period_ends_at'] < $now) {
            return 'expired';
        }
        return (string)$row['status'];
    }

    /**
     * Check whether a user has an active or trialing subscription to any plan.
     */
    public function isActive(string $userId): bool
    {
        $userId = trim($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            "SELECT COUNT(*) FROM subscriptions
             WHERE user_id = :uid AND status IN ('active', 'trialing')
             AND current_period_ends_at >= :now"
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check whether a user has an active/trialing subscription to a specific plan.
     */
    public function isSubscribed(string $userId, string $plan): bool
    {
        return $this->status($userId, $plan) === 'active' || $this->status($userId, $plan) === 'trialing';
    }

    /**
     * List all subscriptions for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId): array
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, plan, status, trial_ends_at, current_period_ends_at,
                    cancelled_at, created_at
             FROM subscriptions WHERE user_id = :uid ORDER BY created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $userId, string $plan): array
    {
        $userId = trim($userId);
        $plan   = trim($plan);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($plan === '') {
            throw new \InvalidArgumentException('plan must not be empty.');
        }
        return [$userId, $plan];
    }
}
