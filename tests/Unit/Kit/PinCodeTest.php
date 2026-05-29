<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PinCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PinCode.
 */
final class PinCodeTest extends TestCase
{
    private PDO $db;
    private PinCode $pc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE pin_codes (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                purpose      VARCHAR(100) NOT NULL,
                pin_hash     VARCHAR(64)  NOT NULL,
                attempts     INTEGER      NOT NULL DEFAULT 0,
                max_attempts INTEGER      NOT NULL DEFAULT 5,
                used         TINYINT(1)   NOT NULL DEFAULT 0,
                expires_at   DATETIME     NOT NULL,
                issued_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pc = new PinCode($this->db);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturns6DigitsDefault(): void
    {
        $pin = $this->pc->issue('user-1', 'phone_verify');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
    }

    public function testIssueReturnsRequestedDigitCount(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}$/', $this->pc->issue('user-1', 'p', digits: 4));
        $this->assertMatchesRegularExpression('/^\d{8}$/', $this->pc->issue('user-2', 'p', digits: 8));
    }

    public function testIssueThrowsOnInvalidDigitCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pc->issue('user-1', 'p', digits: 3);
    }

    public function testIssueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pc->issue('', 'p');
    }

    public function testIssueThrowsOnEmptyPurpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pc->issue('user-1', '');
    }

    public function testIssueInvalidatesPreviousPin(): void
    {
        $old = $this->pc->issue('user-1', 'verify');
        $this->pc->issue('user-1', 'verify'); // new PIN
        $this->assertFalse($this->pc->verify('user-1', 'verify', $old));
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function testVerifyReturnsTrueForCorrectPin(): void
    {
        $pin = $this->pc->issue('user-1', 'verify');
        $this->assertTrue($this->pc->verify('user-1', 'verify', $pin));
    }

    public function testVerifyReturnsFalseForWrongPin(): void
    {
        $this->pc->issue('user-1', 'verify');
        $this->assertFalse($this->pc->verify('user-1', 'verify', '000000'));
    }

    public function testVerifyDoesNotConsumePin(): void
    {
        $pin = $this->pc->issue('user-1', 'verify');
        $this->pc->verify('user-1', 'verify', $pin);
        $this->assertTrue($this->pc->verify('user-1', 'verify', $pin));
    }

    public function testVerifyReturnsFalseForExpiredPin(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $pin  = '123456';
        $this->db->exec("INSERT INTO pin_codes (user_id, purpose, pin_hash, expires_at)
            VALUES ('user-1', 'verify', '" . hash('sha256', $pin) . "', '{$past}')");
        $this->assertFalse($this->pc->verify('user-1', 'verify', $pin));
    }

    public function testVerifyLocksAfterMaxAttempts(): void
    {
        $pin = $this->pc->issue('user-1', 'verify', maxAttempts: 3);
        for ($i = 0; $i < 3; $i++) {
            $this->pc->verify('user-1', 'verify', 'wrong');
        }
        // Even correct PIN should now fail
        $this->assertFalse($this->pc->verify('user-1', 'verify', $pin));
    }

    // ── consume ───────────────────────────────────────────────────────────────

    public function testConsumeReturnsTrueAndMarksUsed(): void
    {
        $pin = $this->pc->issue('user-1', 'verify');
        $this->assertTrue($this->pc->consume('user-1', 'verify', $pin));
        // Second use should fail
        $this->assertFalse($this->pc->consume('user-1', 'verify', $pin));
    }

    public function testConsumeReturnsFalseForWrongPin(): void
    {
        $this->pc->issue('user-1', 'verify');
        $this->assertFalse($this->pc->consume('user-1', 'verify', '000000'));
    }

    // ── invalidate ────────────────────────────────────────────────────────────

    public function testInvalidateMakesPinUnusable(): void
    {
        $pin = $this->pc->issue('user-1', 'verify');
        $this->assertGreaterThan(0, $this->pc->invalidate('user-1', 'verify'));
        $this->assertFalse($this->pc->verify('user-1', 'verify', $pin));
    }

    public function testInvalidateReturnsZeroWhenNoneActive(): void
    {
        $this->assertSame(0, $this->pc->invalidate('user-1', 'verify'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesExpiredRows(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO pin_codes (user_id, purpose, pin_hash, expires_at)
            VALUES ('user-1', 'p', 'hash', '{$past}')");
        $count = $this->pc->purgeExpired();
        $this->assertGreaterThan(0, $count);
    }

    public function testPurgeExpiredKeepsActiveRows(): void
    {
        $pin = $this->pc->issue('user-1', 'verify', ttlSeconds: 3600);
        $this->pc->purgeExpired();
        $this->assertTrue($this->pc->verify('user-1', 'verify', $pin));
    }
}
