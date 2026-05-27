<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * User subscription / plan management.
 *
 * Tracks which plan a user is on, when it expires, and provides an
 * upgrade/downgrade/cancel/renew lifecycle. A history table records
 * all plan changes for audit purposes.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE subscriptions (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL UNIQUE,
 *     plan       VARCHAR(64)  NOT NULL,
 *     status     VARCHAR(16)  NOT NULL DEFAULT 'active',
 *     expires_at DATETIME     DEFAULT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE subscription_history (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     plan       VARCHAR(64)  NOT NULL,
 *     action     VARCHAR(32)  NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_subscription_history_user ON subscription_history (user_id);
 * ```
 *
 * ## Status values
 *
 * - `active`    — subscription is running; may have an expiry date.
 * - `cancelled` — user has cancelled; may still be active until expires_at.
 * - `expired`   — past expires_at; isActive() returns false.
 *
 * ## Usage
 *
 * ```php
 * $sub = new Subscription($pdo);
 *
 * $sub->subscribe('user:1', 'pro', expiresIn: 86400 * 30);
 * $sub->isActive('user:1');         // true
 * $sub->currentPlan('user:1');      // 'pro'
 *
 * $sub->changePlan('user:1', 'enterprise');
 * $sub->cancel('user:1');
 * $sub->renew('user:1', 86400 * 30);
 *
 * $sub->history('user:1');          // plan change log
 * ```
 */
final class Subscription
{
    private const TABLE_SUBS    = 'subscriptions';
    private const TABLE_HISTORY = 'subscription_history';

    private const STATUS_ACTIVE    = 'active';
    private const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $tableSubs    = self::TABLE_SUBS,
        private readonly string $tableHistory = self::TABLE_HISTORY,
    ) {
    }

    /**
     * Subscribe a user to a plan. Replaces any existing subscription.
     *
     * @param string   $userId    Application-level user identifier.
     * @param string   $plan      Plan name (e.g. 'free', 'pro', 'enterprise').
     * @param int|null $expiresIn Seconds until expiry. Null = no expiry.
     */
    public function subscribe(string $userId, string $plan, ?int $expiresIn = null): void
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $driver    = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expiresAt = $expiresIn !== null
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $expiresIn . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR REPLACE INTO ' . $this->tableSubs .
                   ' (user_id, plan, status, expires_at, created_at, updated_at)' .
                   ' VALUES (:u, :plan, :status, :exp, :now, :now)';
        } else {
            $sql = 'INSERT INTO ' . $this->tableSubs .
                   ' (user_id, plan, status, expires_at, created_at, updated_at)' .
                   ' VALUES (:u, :plan, :status, :exp, :now, :now)' .
                   ' ON DUPLICATE KEY UPDATE plan = VALUES(plan), status = VALUES(status),' .
                   ' expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)';
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':plan', $plan, PDO::PARAM_STR);
        $stmt->bindValue(':status', self::STATUS_ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();

        $this->recordHistory($userId, $plan, 'subscribe', $db);
    }

    /**
     * Change a user's plan without altering status or expiry.
     *
     * Returns false if the user has no subscription.
     *
     * @param string $userId Application-level user identifier.
     * @param string $plan   New plan name.
     */
    public function changePlan(string $userId, string $plan): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->tableSubs .
            ' SET plan = :plan, updated_at = :now WHERE user_id = :u'
        );
        $stmt->bindValue(':plan', $plan, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->recordHistory($userId, $plan, 'change', $db);
        return true;
    }

    /**
     * Cancel a subscription. Sets status to 'cancelled' but keeps the row.
     *
     * Returns false if no subscription found.
     *
     * @param string $userId Application-level user identifier.
     */
    public function cancel(string $userId): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->tableSubs .
            ' SET status = :status, updated_at = :now WHERE user_id = :u AND status != :status'
        );
        $stmt->bindValue(':status', self::STATUS_CANCELLED, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $plan = $this->currentPlan($userId) ?? '';
        $this->recordHistory($userId, $plan, 'cancel', $db);
        return true;
    }

    /**
     * Renew a subscription: sets status to 'active' and extends expiry.
     *
     * Returns false if no subscription found.
     *
     * @param string   $userId    Application-level user identifier.
     * @param int|null $expiresIn Seconds from now until new expiry. Null = no expiry.
     */
    public function renew(string $userId, ?int $expiresIn = null): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expiresAt = $expiresIn !== null
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $expiresIn . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        $stmt = $db->prepare(
            'UPDATE ' . $this->tableSubs .
            ' SET status = :status, expires_at = :exp, updated_at = :now WHERE user_id = :u'
        );
        $stmt->bindValue(':status', self::STATUS_ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $plan = $this->currentPlan($userId) ?? '';
        $this->recordHistory($userId, $plan, 'renew', $db);
        return true;
    }

    /**
     * Check whether a user has an active, non-expired subscription.
     *
     * @param string $userId Application-level user identifier.
     */
    public function isActive(string $userId): bool
    {
        $row = $this->findRow($userId);
        if ($row === null || $row['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($row['expires_at'] !== null) {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if ((string)$row['expires_at'] <= $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the current plan name for a user, or null if no subscription.
     *
     * @param string $userId Application-level user identifier.
     */
    public function currentPlan(string $userId): ?string
    {
        $row = $this->findRow($userId);
        return $row !== null ? (string)$row['plan'] : null;
    }

    /**
     * Get subscription history for a user, newest first.
     *
     * @param  string $userId Application-level user identifier.
     * @return list<array{id:int,plan:string,action:string,created_at:string}>
     */
    public function history(string $userId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, plan, action, created_at FROM ' . $this->tableHistory .
            ' WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'         => (int)$r['id'],
            'plan'       => (string)$r['plan'],
            'action'     => (string)$r['action'],
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * @return array{plan:string,status:string,expires_at:string|null}|null
     */
    private function findRow(string $userId): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT plan, status, expires_at FROM ' . $this->tableSubs .
            ' WHERE user_id = :u LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'plan'       => (string)$row['plan'],
            'status'     => (string)$row['status'],
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
        ];
    }

    private function recordHistory(string $userId, string $plan, string $action, PDO $db): void
    {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO ' . $this->tableHistory .
            ' (user_id, plan, action, created_at) VALUES (:u, :plan, :action, :now)'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':plan', $plan, PDO::PARAM_STR);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();
    }
}
