<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * GiftCard — prepaid gift card balance with partial redemption support.
 *
 * Each gift card has a fixed initial balance in cents. Holders can
 * redeem partial or full amounts; the remaining balance is tracked.
 * Unlike CouponCode (single-use discount), a gift card can be used
 * across multiple transactions until the balance is exhausted.
 *
 * ## Usage
 *
 * ```php
 * $gc = new GiftCard($pdo);
 *
 * // Issue a $50 gift card
 * $id = $gc->issue(5000, 'GC-SUMMER');
 *
 * // Apply $20 from the card at checkout
 * $applied = $gc->redeem('GC-SUMMER', 2000);
 * // $applied = 2000; remaining balance = 3000
 *
 * // Check balance
 * $balance = $gc->balance('GC-SUMMER');  // 3000
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE gift_cards (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code            VARCHAR(100) NOT NULL UNIQUE,
 *     initial_balance INTEGER      NOT NULL,
 *     balance         INTEGER      NOT NULL,
 *     status          VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     expires_at      DATETIME     NULL,
 *     created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE gift_card_redemptions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     card_id     INTEGER      NOT NULL,
 *     amount      INTEGER      NOT NULL,
 *     note        TEXT         NULL,
 *     redeemed_at DATETIME     NOT NULL
 * );
 * ```
 */
final class GiftCard
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_VOID     = 'void';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new gift card.
     *
     * @param int         $balanceCents Initial balance in smallest currency unit.
     * @param string      $code         Unique code (generated if empty).
     * @param string|null $expiresAt    Optional expiry datetime.
     * @return int New gift card ID.
     * @throws \InvalidArgumentException on balance <= 0.
     * @throws \RuntimeException if code already exists.
     */
    public function issue(int $balanceCents, string $code = '', ?string $expiresAt = null): int
    {
        if ($balanceCents <= 0) {
            throw new \InvalidArgumentException('balance must be > 0.');
        }
        $code = strtoupper(trim($code));
        if ($code === '') {
            $code = strtoupper(bin2hex(random_bytes(6)));
        }

        if ($this->find($code) !== null) {
            throw new \RuntimeException("Gift card code '{$code}' already exists.");
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO gift_cards
                 (code, initial_balance, balance, status, expires_at, created_at)
             VALUES (:code, :init, :bal, :status, :expires, :now)'
        );
        $stmt->execute([
            ':code'    => $code,
            ':init'    => $balanceCents,
            ':bal'     => $balanceCents,
            ':status'  => self::STATUS_ACTIVE,
            ':expires' => $expiresAt,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a gift card by its code.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $code): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM gift_cards WHERE code = :code');
        $stmt->execute([':code' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Retrieve a gift card by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM gift_cards WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the current balance in cents. Returns null if card not found.
     */
    public function balance(string $code): ?int
    {
        $card = $this->find($code);
        return $card !== null ? (int)$card['balance'] : null;
    }

    /**
     * Return true if the card is active, not expired, and has balance > 0.
     */
    public function isRedeemable(string $code): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $card = $this->find($code);
        if ($card === null || $card['status'] !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($card['expires_at'] !== null && $card['expires_at'] <= $now) {
            return false;
        }
        return (int)$card['balance'] > 0;
    }

    /**
     * Redeem an amount from a gift card.
     *
     * If $amountCents exceeds the remaining balance, only the remaining
     * balance is applied. Returns the actual amount applied.
     *
     * @throws \RuntimeException if card is not redeemable.
     */
    public function redeem(string $code, int $amountCents, ?string $note = null): int
    {
        if (!$this->isRedeemable($code)) {
            throw new \RuntimeException("Gift card '{$code}' is not redeemable.");
        }

        $card    = $this->find($code);
        if ($card === null) {
            throw new \RuntimeException('Gift card not found.');
        }

        $applied    = min($amountCents, (int)$card['balance']);
        $newBalance = (int)$card['balance'] - $applied;
        $newStatus  = $newBalance === 0 ? self::STATUS_EXHAUSTED : self::STATUS_ACTIVE;

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE gift_cards SET balance = :bal, status = :status WHERE id = :id'
        );
        $stmt->execute([':bal' => $newBalance, ':status' => $newStatus, ':id' => (int)$card['id']]);

        $stmt = $this->db()->prepare(
            'INSERT INTO gift_card_redemptions (card_id, amount, note, redeemed_at)
             VALUES (:cid, :amount, :note, :now)'
        );
        $stmt->execute([':cid' => (int)$card['id'], ':amount' => $applied, ':note' => $note, ':now' => $now]);

        return $applied;
    }

    /**
     * Void a gift card (prevent any further use).
     *
     * @return bool True if found and updated.
     */
    public function void(string $code): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE gift_cards SET status = :status WHERE code = :code'
        );
        $stmt->execute([':status' => self::STATUS_VOID, ':code' => strtoupper(trim($code))]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark expired gift cards (expires_at <= $asOf) as STATUS_EXPIRED.
     *
     * Returns the number of rows updated.
     */
    public function expireStale(string $asOf = ''): int
    {
        $now  = $asOf !== '' ? $asOf : (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE gift_cards SET status = :expired
             WHERE status = :active AND expires_at IS NOT NULL AND expires_at <= :now'
        );
        $stmt->execute([':expired' => self::STATUS_EXPIRED, ':active' => self::STATUS_ACTIVE, ':now' => $now]);
        return $stmt->rowCount();
    }

    /**
     * List all redemption events for a gift card.
     *
     * @return list<array<string,mixed>>
     */
    public function redemptions(string $code): array
    {
        $card = $this->find($code);
        if ($card === null) {
            return [];
        }
        $stmt = $this->db()->prepare(
            'SELECT * FROM gift_card_redemptions
             WHERE card_id = :cid ORDER BY redeemed_at ASC, id ASC'
        );
        $stmt->execute([':cid' => (int)$card['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
