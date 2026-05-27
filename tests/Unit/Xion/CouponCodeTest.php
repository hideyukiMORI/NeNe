<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\CouponCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CouponCode using an in-memory SQLite database.
 */
final class CouponCodeTest extends TestCase
{
    private PDO $db;
    private CouponCode $coupons;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE coupon_codes (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                code       VARCHAR(64)  NOT NULL UNIQUE,
                discount   INTEGER      NOT NULL DEFAULT 0,
                max_uses   INTEGER      DEFAULT NULL,
                expires_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE coupon_redemptions (
                id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                coupon_id   INTEGER      NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                redeemed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (coupon_id, user_id)
            )'
        );
        $this->coupons = new CouponCode($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->coupons->create('SAVE10');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateWithDiscount(): void
    {
        $this->coupons->create('SAVE20', 20);
        $coupon = $this->coupons->find('SAVE20');
        $this->assertNotNull($coupon);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(20, $coupon['discount']);
    }

    public function testCreateWithMaxUses(): void
    {
        $this->coupons->create('LIMITED', 10, maxUses: 5);
        $coupon = $this->coupons->find('LIMITED');
        $this->assertNotNull($coupon);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(5, $coupon['max_uses']);
    }

    public function testCreateWithExpiryStoresExpiresAt(): void
    {
        $this->coupons->create('EXPIRE', 0, expiresIn: 3600);
        $coupon = $this->coupons->find('EXPIRE');
        $this->assertNotNull($coupon);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertNotNull($coupon['expires_at']);
    }

    // ── isValid ───────────────────────────────────────────────────────────────

    public function testIsValidReturnsTrueForValidCode(): void
    {
        $this->coupons->create('VALID10');
        $this->assertTrue($this->coupons->isValid('VALID10', 'user:1'));
    }

    public function testIsValidReturnsFalseForUnknownCode(): void
    {
        $this->assertFalse($this->coupons->isValid('UNKNOWN', 'user:1'));
    }

    public function testIsValidReturnsFalseForExpiredCoupon(): void
    {
        $id = $this->coupons->create('EXPIRED', 0, expiresIn: 3600);
        $this->db->exec("UPDATE coupon_codes SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->assertFalse($this->coupons->isValid('EXPIRED', 'user:1'));
    }

    public function testIsValidReturnsFalseWhenMaxUsesReached(): void
    {
        $this->coupons->create('MAXED', 0, maxUses: 1);
        $this->coupons->redeem('MAXED', 'user:1');
        $this->assertFalse($this->coupons->isValid('MAXED', 'user:2'));
    }

    public function testIsValidReturnsFalseIfUserAlreadyRedeemed(): void
    {
        $this->coupons->create('USED');
        $this->coupons->redeem('USED', 'user:1');
        $this->assertFalse($this->coupons->isValid('USED', 'user:1'));
    }

    // ── redeem ────────────────────────────────────────────────────────────────

    public function testRedeemValidCouponReturnsArray(): void
    {
        $this->coupons->create('PROMO10', 10);
        $result = $this->coupons->redeem('PROMO10', 'user:1');
        $this->assertIsArray($result);
        $this->assertSame(10, $result['discount']);
        $this->assertSame('PROMO10', $result['code']);
    }

    public function testRedeemReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->coupons->redeem('UNKNOWN', 'user:1'));
    }

    public function testRedeemReturnsNullForExpiredCoupon(): void
    {
        $id = $this->coupons->create('OLD', 0, expiresIn: 3600);
        $this->db->exec("UPDATE coupon_codes SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->assertNull($this->coupons->redeem('OLD', 'user:1'));
    }

    public function testRedeemReturnsNullIfAlreadyRedeemedByUser(): void
    {
        $this->coupons->create('ONCE');
        $this->coupons->redeem('ONCE', 'user:1');
        $this->assertNull($this->coupons->redeem('ONCE', 'user:1'));
    }

    public function testRedeemReturnsNullWhenMaxUsesReached(): void
    {
        $this->coupons->create('LIMIT1', 0, maxUses: 1);
        $this->coupons->redeem('LIMIT1', 'user:1');
        $this->assertNull($this->coupons->redeem('LIMIT1', 'user:2'));
    }

    public function testDifferentUsersCanRedeemSameCoupon(): void
    {
        $this->coupons->create('MULTI', 5, maxUses: 3);
        $this->assertNotNull($this->coupons->redeem('MULTI', 'user:1'));
        $this->assertNotNull($this->coupons->redeem('MULTI', 'user:2'));
        $this->assertNotNull($this->coupons->redeem('MULTI', 'user:3'));
        $this->assertNull($this->coupons->redeem('MULTI', 'user:4'));
    }

    public function testRedeemResultContainsRedeemedAt(): void
    {
        $this->coupons->create('STAMP');
        $result = $this->coupons->redeem('STAMP', 'user:1');
        $this->assertNotNull($result);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertArrayHasKey('redeemed_at', $result);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsArrayForExistingCode(): void
    {
        $this->coupons->create('FIND10', 10);
        $coupon = $this->coupons->find('FIND10');
        $this->assertIsArray($coupon);
        $this->assertSame('FIND10', $coupon['code']);
    }

    public function testFindReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->coupons->find('NOTFOUND'));
    }

    public function testFindMaxUsesIsNullWhenUnlimited(): void
    {
        $this->coupons->create('UNLIMITED');
        $coupon = $this->coupons->find('UNLIMITED');
        $this->assertNotNull($coupon);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertNull($coupon['max_uses']);
    }
}
