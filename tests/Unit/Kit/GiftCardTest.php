<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\GiftCard;
use PDO;
use PHPUnit\Framework\TestCase;

final class GiftCardTest extends TestCase
{
    private PDO $pdo;
    private GiftCard $gc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE gift_cards (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                code            VARCHAR(100) NOT NULL UNIQUE,
                initial_balance INTEGER      NOT NULL,
                balance         INTEGER      NOT NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT \'active\',
                expires_at      DATETIME     NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE gift_card_redemptions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                card_id     INTEGER      NOT NULL,
                amount      INTEGER      NOT NULL,
                note        TEXT         NULL,
                redeemed_at DATETIME     NOT NULL
            )
        ');
        $this->gc = new GiftCard($this->pdo);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturnsId(): void
    {
        $id = $this->gc->issue(5000, 'GC-TEST');
        $this->assertGreaterThan(0, $id);
    }

    public function testIssueStoresFields(): void
    {
        $this->gc->issue(5000, 'GC-TEST');
        $card = $this->gc->find('GC-TEST');
        $this->assertNotNull($card);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(5000, (int)$card['initial_balance']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(5000, (int)$card['balance']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GiftCard::STATUS_ACTIVE, $card['status']);
    }

    public function testIssueNormalizesCodeToUppercase(): void
    {
        $this->gc->issue(5000, 'gc-lower');
        $card = $this->gc->find('GC-LOWER');
        $this->assertNotNull($card);
    }

    public function testIssueThrowsOnZeroBalance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gc->issue(0, 'GC-BAD');
    }

    public function testIssueThrowsOnNegativeBalance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gc->issue(-100, 'GC-BAD');
    }

    public function testIssueThrowsOnDuplicateCode(): void
    {
        $this->gc->issue(5000, 'GC-DUP');
        $this->expectException(\RuntimeException::class);
        $this->gc->issue(3000, 'GC-DUP');
    }

    public function testIssueGeneratesCodeWhenEmpty(): void
    {
        $id   = $this->gc->issue(5000);
        $card = $this->gc->get($id);
        $this->assertNotNull($card);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotEmpty($card['code']);
    }

    // ── find / get ────────────────────────────────────────────────────────────

    public function testFindReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->gc->find('UNKNOWN'));
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->gc->get(9999));
    }

    // ── balance ───────────────────────────────────────────────────────────────

    public function testBalanceReturnsInitialBalance(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->assertSame(5000, $this->gc->balance('GC-A'));
    }

    public function testBalanceReturnsNullForUnknown(): void
    {
        $this->assertNull($this->gc->balance('UNKNOWN'));
    }

    // ── isRedeemable ──────────────────────────────────────────────────────────

    public function testIsRedeemableTrueForActiveCard(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->assertTrue($this->gc->isRedeemable('GC-A'));
    }

    public function testIsRedeemableFalseForUnknown(): void
    {
        $this->assertFalse($this->gc->isRedeemable('UNKNOWN'));
    }

    public function testIsRedeemableFalseForVoided(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->gc->void('GC-A');
        $this->assertFalse($this->gc->isRedeemable('GC-A'));
    }

    public function testIsRedeemableFalseForExpired(): void
    {
        $this->gc->issue(5000, 'GC-A', '2020-01-01 00:00:00');
        $this->assertFalse($this->gc->isRedeemable('GC-A'));
    }

    // ── redeem ────────────────────────────────────────────────────────────────

    public function testRedeemReducesBalance(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $applied = $this->gc->redeem('GC-A', 2000);
        $this->assertSame(2000, $applied);
        $this->assertSame(3000, $this->gc->balance('GC-A'));
    }

    public function testRedeemCapsAtRemainingBalance(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $applied = $this->gc->redeem('GC-A', 9999);
        $this->assertSame(5000, $applied);
        $this->assertSame(0, $this->gc->balance('GC-A'));
    }

    public function testRedeemMarksExhaustedWhenBalanceReachesZero(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->gc->redeem('GC-A', 5000);
        $card = $this->gc->find('GC-A');
        $this->assertNotNull($card);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GiftCard::STATUS_EXHAUSTED, $card['status']);
    }

    public function testRedeemThrowsWhenNotRedeemable(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->gc->void('GC-A');
        $this->expectException(\RuntimeException::class);
        $this->gc->redeem('GC-A', 1000);
    }

    public function testRedeemRecordsRedemptionRow(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->gc->redeem('GC-A', 2000, 'Order #1');
        $list = $this->gc->redemptions('GC-A');
        $this->assertCount(1, $list);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2000, (int)$list[0]['amount']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Order #1', $list[0]['note']);
    }

    // ── void ──────────────────────────────────────────────────────────────────

    public function testVoidChangesStatus(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->assertTrue($this->gc->void('GC-A'));
        $card = $this->gc->find('GC-A');
        $this->assertNotNull($card);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GiftCard::STATUS_VOID, $card['status']);
    }

    public function testVoidReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->gc->void('UNKNOWN'));
    }

    // ── expireStale ───────────────────────────────────────────────────────────

    public function testExpireStaleMarksExpired(): void
    {
        $this->gc->issue(5000, 'GC-A', '2026-01-01 00:00:00');
        $count = $this->gc->expireStale('2026-06-01 00:00:00');
        $this->assertSame(1, $count);
        $card = $this->gc->find('GC-A');
        $this->assertNotNull($card);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(GiftCard::STATUS_EXPIRED, $card['status']);
    }

    public function testExpireStaleDoesNotExpireNonExpired(): void
    {
        $this->gc->issue(5000, 'GC-A', '2099-01-01 00:00:00');
        $count = $this->gc->expireStale('2026-06-01 00:00:00');
        $this->assertSame(0, $count);
    }

    // ── redemptions ───────────────────────────────────────────────────────────

    public function testRedemptionsReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->gc->redemptions('UNKNOWN'));
    }

    public function testRedemptionsReturnsAllEvents(): void
    {
        $this->gc->issue(5000, 'GC-A');
        $this->gc->redeem('GC-A', 1000);
        $this->gc->redeem('GC-A', 2000);
        $this->assertCount(2, $this->gc->redemptions('GC-A'));
    }
}
