<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PasswordResetToken;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    // -------------------------------------------------------------------------
    // generate()
    // -------------------------------------------------------------------------

    public function testGenerateReturns64CharLowercaseHex(): void
    {
        $token = PasswordResetToken::generate();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsDifferentValueEachCall(): void
    {
        $first  = PasswordResetToken::generate();
        $second = PasswordResetToken::generate();

        $this->assertNotSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // hash()
    // -------------------------------------------------------------------------

    public function testHashReturns64CharHex(): void
    {
        $hash = PasswordResetToken::hash('abc');

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testHashMatchesPhpHashSha256(): void
    {
        $raw = 'abc';

        $this->assertSame(hash('sha256', $raw), PasswordResetToken::hash($raw));
    }

    // -------------------------------------------------------------------------
    // verify()
    // -------------------------------------------------------------------------

    public function testVerifyReturnsTrueForMatchingToken(): void
    {
        $raw = 'some-raw-token';

        $this->assertTrue(PasswordResetToken::verify($raw, PasswordResetToken::hash($raw)));
    }

    public function testVerifyReturnsFalseForWrongToken(): void
    {
        $this->assertFalse(PasswordResetToken::verify('wrong', PasswordResetToken::hash('correct')));
    }

    // -------------------------------------------------------------------------
    // isExpired()
    // -------------------------------------------------------------------------

    public function testIsExpiredReturnsFalseWhenExpiresAtIsNull(): void
    {
        $this->assertFalse(PasswordResetToken::isExpired(null));
    }

    public function testIsExpiredReturnsTrueForPastDatetime(): void
    {
        $this->assertTrue(PasswordResetToken::isExpired('2020-01-01 00:00:00'));
    }

    public function testIsExpiredReturnsFalseForFutureDatetime(): void
    {
        $this->assertFalse(PasswordResetToken::isExpired('2099-12-31 23:59:59'));
    }

    public function testIsExpiredReturnsTrueWhenCurrentEqualsExpiry(): void
    {
        // Same moment is considered expired
        $this->assertTrue(
            PasswordResetToken::isExpired('2026-01-01 12:00:00', '2026-01-01 12:00:00')
        );
    }

    public function testIsExpiredReturnsFalseOneSecondBeforeExpiry(): void
    {
        $this->assertFalse(
            PasswordResetToken::isExpired('2026-01-01 12:00:01', '2026-01-01 12:00:00')
        );
    }

    // -------------------------------------------------------------------------
    // expiresAt()
    // -------------------------------------------------------------------------

    public function testExpiresAtReturns60MinutesFromNowByDefault(): void
    {
        $before  = time();
        $expires = PasswordResetToken::expiresAt(60);
        $after   = time();

        $ts = strtotime($expires);
        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual($before + 3600, $ts);
        $this->assertLessThanOrEqual($after + 3600, $ts);
    }

    public function testExpiresAtWithFixedNowReturns30MinutesLater(): void
    {
        $result = PasswordResetToken::expiresAt(30, '2026-01-01 12:00:00');

        $this->assertSame('2026-01-01 12:30:00', $result);
    }
}
