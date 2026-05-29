<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\InviteCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InviteCode.
 */
final class InviteCodeTest extends TestCase
{
    private PDO $db;
    private InviteCode $ic;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE invite_codes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code       VARCHAR(12)  NOT NULL UNIQUE,
                created_by VARCHAR(255) NOT NULL DEFAULT \'\',
                label      VARCHAR(255) NOT NULL DEFAULT \'\',
                max_uses   INTEGER      DEFAULT NULL,
                uses       INTEGER      NOT NULL DEFAULT 0,
                expires_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE invite_code_uses (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                code    VARCHAR(12)  NOT NULL,
                user_id VARCHAR(255) NOT NULL,
                used_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (code, user_id)
            )
        ');
        $this->ic = new InviteCode($this->db);
    }

    // ── generate ──────────────────────────────────────────────────────────────

    public function testGenerateReturns8CharCode(): void
    {
        $code = $this->ic->generate();
        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
    }

    public function testGenerateProducesUniqueCodesEachCall(): void
    {
        // Statistically guaranteed with 32^8 possibilities
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->ic->generate();
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testGenerateWithMaxUses(): void
    {
        $code = $this->ic->generate(maxUses: 3);
        $row  = $this->ic->find($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3, (int)$row['max_uses']);
    }

    // ── redeem ────────────────────────────────────────────────────────────────

    public function testRedeemReturnsTrueForValidCode(): void
    {
        $code = $this->ic->generate();
        $this->assertTrue($this->ic->redeem($code, 'user-1'));
    }

    public function testRedeemIncrementsUsesCount(): void
    {
        $code = $this->ic->generate();
        $this->ic->redeem($code, 'user-1');
        $row = $this->ic->find($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['uses']);
    }

    public function testRedeemReturnsFalseForInvalidCode(): void
    {
        $this->assertFalse($this->ic->redeem('XXXXXXXX', 'user-1'));
    }

    public function testRedeemReturnsFalseWhenExhausted(): void
    {
        $code = $this->ic->generate(maxUses: 1);
        $this->ic->redeem($code, 'user-1');
        $this->assertFalse($this->ic->redeem($code, 'user-2'));
    }

    public function testRedeemReturnsFalseForExpiredCode(): void
    {
        $past = new \DateTimeImmutable('-1 second');
        $code = $this->ic->generate(expiresAt: $past);
        $this->assertFalse($this->ic->redeem($code, 'user-1'));
    }

    public function testRedeemThrowsIfUserAlreadyRedeemed(): void
    {
        $code = $this->ic->generate(maxUses: 5);
        $this->ic->redeem($code, 'user-1');
        $this->expectException(\RuntimeException::class);
        $this->ic->redeem($code, 'user-1');
    }

    // ── isValid ───────────────────────────────────────────────────────────────

    public function testIsValidTrueForActiveCode(): void
    {
        $code = $this->ic->generate();
        $this->assertTrue($this->ic->isValid($code));
    }

    public function testIsValidFalseForExhaustedCode(): void
    {
        $code = $this->ic->generate(maxUses: 1);
        $this->ic->redeem($code, 'user-1');
        $this->assertFalse($this->ic->isValid($code));
    }

    public function testIsValidFalseForExpiredCode(): void
    {
        $past = new \DateTimeImmutable('-1 second');
        $code = $this->ic->generate(expiresAt: $past);
        $this->assertFalse($this->ic->isValid($code));
    }

    public function testIsValidFalseForUnknownCode(): void
    {
        $this->assertFalse($this->ic->isValid('ZZZZZZZZ'));
    }

    public function testUnlimitedCodeIsAlwaysValid(): void
    {
        $code = $this->ic->generate(); // no maxUses
        $this->ic->redeem($code, 'user-1');
        $this->ic->redeem($code, 'user-2');
        $this->assertTrue($this->ic->isValid($code));
    }

    // ── redemptions ───────────────────────────────────────────────────────────

    public function testRedemptionsListsUsers(): void
    {
        $code = $this->ic->generate();
        $this->ic->redeem($code, 'user-1');
        $this->ic->redeem($code, 'user-2');
        $this->assertSame(['user-1', 'user-2'], $this->ic->redemptions($code));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeDeletesCode(): void
    {
        $code = $this->ic->generate();
        $this->assertTrue($this->ic->revoke($code));
        $this->assertNull($this->ic->find($code));
    }

    public function testRevokeDeletesRedemptionRecords(): void
    {
        $code = $this->ic->generate();
        $this->ic->redeem($code, 'user-1');
        $this->ic->revoke($code);
        $this->assertSame([], $this->ic->redemptions($code));
    }

    public function testRevokeReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->ic->revoke('NOTFOUND'));
    }
}
