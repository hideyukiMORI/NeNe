<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Coupon / promo code management with usage limits and per-user redemption tracking.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE coupon_codes (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     code       VARCHAR(64)  NOT NULL UNIQUE,
 *     discount   INTEGER      NOT NULL DEFAULT 0,
 *     max_uses   INTEGER      DEFAULT NULL,
 *     expires_at DATETIME     DEFAULT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE coupon_redemptions (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     coupon_id   INTEGER      NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     redeemed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (coupon_id, user_id)
 * );
 * ```
 *
 * ## Usage
 *
 * ```php
 * $coupons = new CouponCode($pdo);
 *
 * // Create a coupon: code, discount value, max uses, TTL
 * $id = $coupons->create('SUMMER20', 20, maxUses: 100, expiresIn: 86400 * 30);
 *
 * // Validate without redeeming
 * $coupons->isValid('SUMMER20', 'user:1');  // true
 *
 * // Redeem (atomic)
 * $result = $coupons->redeem('SUMMER20', 'user:1');
 * if ($result === null) {
 *     // invalid, expired, over limit, or already used
 * }
 * echo $result['discount'];  // 20
 *
 * // Lookup
 * $coupons->find('SUMMER20');
 * ```
 */
final class CouponCode
{
    private const TABLE_COUPONS      = 'coupon_codes';
    private const TABLE_REDEMPTIONS  = 'coupon_redemptions';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $tableCoupons     = self::TABLE_COUPONS,
        private readonly string $tableRedemptions = self::TABLE_REDEMPTIONS,
    ) {
    }

    /**
     * Create a new coupon code.
     *
     * @param  string   $code      Unique promo code (e.g. 'SUMMER20').
     * @param  int      $discount  Discount value (application-defined semantics).
     * @param  int|null $maxUses   Maximum total redemptions. Null = unlimited.
     * @param  int|null $expiresIn Seconds until expiry. Null = no expiry.
     * @return int New coupon ID.
     */
    public function create(
        string $code,
        int $discount = 0,
        ?int $maxUses = null,
        ?int $expiresIn = null,
    ): int {
        $expiresAt = $expiresIn !== null
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $expiresIn . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO ' . $this->tableCoupons .
            ' (code, discount, max_uses, expires_at)' .
            ' VALUES (:code, :discount, :max, :exp)'
        );
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':discount', $discount, PDO::PARAM_INT);
        $stmt->bindValue(':max', $maxUses, $maxUses !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':exp', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Check whether a coupon is valid for a specific user.
     *
     * A coupon is valid when:
     * - The code exists
     * - It has not expired
     * - The usage limit has not been reached
     * - The user has not already redeemed it
     *
     * @param string $code   Promo code to validate.
     * @param string $userId User attempting to use the coupon.
     */
    public function isValid(string $code, string $userId): bool
    {
        return $this->resolve($code, $userId) !== null;
    }

    /**
     * Redeem a coupon for a user.
     *
     * Atomically validates and records the redemption. Returns coupon data on success,
     * null on any failure (invalid code, expired, over limit, already used by this user).
     *
     * @param  string $code   Promo code to redeem.
     * @param  string $userId User redeeming the coupon.
     * @return array{id:int,code:string,discount:int,max_uses:int|null,expires_at:string|null,redeemed_at:string}|null
     */
    public function redeem(string $code, string $userId): ?array
    {
        $db = $this->db ?? PdoConnection::getInstance();
        $db->beginTransaction();

        try {
            $coupon = $this->resolve($code, $userId, $db);
            if ($coupon === null) {
                $db->rollBack();
                return null;
            }

            $redeemedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $driver     = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $ignore     = $driver === 'sqlite' ? 'OR IGNORE' : 'IGNORE';

            $stmt = $db->prepare(
                'INSERT ' . $ignore . ' INTO ' . $this->tableRedemptions .
                ' (coupon_id, user_id, redeemed_at) VALUES (:cid, :uid, :t)'
            );
            $stmt->bindValue(':cid', $coupon['id'], PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':t', $redeemedAt, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                // Race condition: another process redeemed first
                $db->rollBack();
                return null;
            }

            $db->commit();

            return [
                'id'          => $coupon['id'],
                'code'        => $coupon['code'],
                'discount'    => $coupon['discount'],
                'max_uses'    => $coupon['max_uses'],
                'expires_at'  => $coupon['expires_at'],
                'redeemed_at' => $redeemedAt,
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Find a coupon by code, or null if not found.
     *
     * @param  string $code Promo code to look up.
     * @return array{id:int,code:string,discount:int,max_uses:int|null,expires_at:string|null,created_at:string}|null
     */
    public function find(string $code): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, code, discount, max_uses, expires_at, created_at' .
            ' FROM ' . $this->tableCoupons . ' WHERE code = :code LIMIT 1'
        );
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'         => (int)$row['id'],
            'code'       => (string)$row['code'],
            'discount'   => (int)$row['discount'],
            'max_uses'   => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * Resolve and validate a coupon for a user. Returns null if invalid.
     *
     * @return array{id:int,code:string,discount:int,max_uses:int|null,expires_at:string|null}|null
     */
    private function resolve(string $code, string $userId, ?PDO $db = null): ?array
    {
        $db = $db ?? ($this->db ?? PdoConnection::getInstance());

        $stmt = $db->prepare(
            'SELECT id, code, discount, max_uses, expires_at' .
            ' FROM ' . $this->tableCoupons . ' WHERE code = :code LIMIT 1'
        );
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Expiry check
        if ($row['expires_at'] !== null) {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if ((string)$row['expires_at'] <= $now) {
                return null;
            }
        }

        $couponId = (int)$row['id'];

        // Max uses check
        if ($row['max_uses'] !== null) {
            $count = $db->prepare(
                'SELECT COUNT(*) FROM ' . $this->tableRedemptions . ' WHERE coupon_id = :cid'
            );
            $count->bindValue(':cid', $couponId, PDO::PARAM_INT);
            $count->execute();
            if ((int)$count->fetchColumn() >= (int)$row['max_uses']) {
                return null;
            }
        }

        // Per-user duplicate check
        $dup = $db->prepare(
            'SELECT 1 FROM ' . $this->tableRedemptions .
            ' WHERE coupon_id = :cid AND user_id = :uid LIMIT 1'
        );
        $dup->bindValue(':cid', $couponId, PDO::PARAM_INT);
        $dup->bindValue(':uid', $userId, PDO::PARAM_STR);
        $dup->execute();
        if ($dup->fetchColumn() !== false) {
            return null;
        }

        return [
            'id'         => $couponId,
            'code'       => (string)$row['code'],
            'discount'   => (int)$row['discount'],
            'max_uses'   => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
        ];
    }
}
