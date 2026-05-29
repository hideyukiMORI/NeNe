<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TotpAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TotpAuthenticator.
 */
final class TotpAuthenticatorTest extends TestCase
{
    private TotpAuthenticator $totp;

    protected function setUp(): void
    {
        $this->totp = new TotpAuthenticator();
    }

    // ── generateSecret ────────────────────────────────────────────────────────

    public function testGenerateSecretReturnsString(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertIsString($secret);
    }

    public function testGenerateSecretDefaultLengthIs32(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertSame(32, strlen($secret));
    }

    public function testGenerateSecretCustomLength(): void
    {
        $secret = $this->totp->generateSecret(16);
        $this->assertSame(16, strlen($secret));
    }

    public function testGenerateSecretContainsOnlyBase32Chars(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateSecretIsRandom(): void
    {
        $s1 = $this->totp->generateSecret();
        $s2 = $this->totp->generateSecret();
        $this->assertNotSame($s1, $s2);
    }

    // ── generateCode + verifyCode ─────────────────────────────────────────────

    public function testVerifyCodeReturnsTrueForCurrentCode(): void
    {
        $secret = $this->totp->generateSecret();
        $code   = $this->totp->generateCode($secret);
        $this->assertTrue($this->totp->verifyCode($secret, $code));
    }

    public function testVerifyCodeReturnsFalseForWrongCode(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verifyCode($secret, '000000'));
    }

    public function testVerifyCodeReturnsFalseForEmptyCode(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verifyCode($secret, ''));
    }

    public function testVerifyCodeReturnsFalseForNonNumericCode(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verifyCode($secret, 'abcdef'));
    }

    public function testVerifyCodeToleratesPreviousStep(): void
    {
        $secret = $this->totp->generateSecret();
        $time   = time();
        $code   = $this->totp->generateCode($secret, $time - 30);
        // Previous step should be accepted
        $this->assertTrue($this->totp->verifyCode($secret, $code, $time));
    }

    public function testVerifyCodeToleratesNextStep(): void
    {
        $secret = $this->totp->generateSecret();
        $time   = time();
        $code   = $this->totp->generateCode($secret, $time + 30);
        $this->assertTrue($this->totp->verifyCode($secret, $code, $time));
    }

    public function testVerifyCodeStripsSpaces(): void
    {
        $secret = $this->totp->generateSecret();
        $code   = $this->totp->generateCode($secret);
        // Some authenticator apps display codes with a space: "123 456"
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
        $this->assertTrue($this->totp->verifyCode($secret, $spaced));
    }

    public function testGenerateCodeProduces6Digits(): void
    {
        $secret = $this->totp->generateSecret();
        $code   = $this->totp->generateCode($secret);
        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    // ── otpauthUri ────────────────────────────────────────────────────────────

    public function testOtpauthUriStartsWithOtpauth(): void
    {
        $secret = $this->totp->generateSecret();
        $uri    = $this->totp->otpauthUri($secret, 'user@example.com', 'MyApp');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
    }

    public function testOtpauthUriContainsSecret(): void
    {
        $secret = $this->totp->generateSecret();
        $uri    = $this->totp->otpauthUri($secret, 'user@example.com', 'MyApp');
        $this->assertStringContainsString('secret=' . $secret, $uri);
    }

    // ── backup codes ──────────────────────────────────────────────────────────

    public function testGenerateBackupCodesReturnsCorrectCount(): void
    {
        $codes = $this->totp->generateBackupCodes(6);
        $this->assertCount(6, $codes);
    }

    public function testGenerateBackupCodesHaveExpectedFormat(): void
    {
        $codes = $this->totp->generateBackupCodes(1);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $codes[0]);
    }

    public function testHashAndVerifyBackupCode(): void
    {
        $codes = $this->totp->generateBackupCodes(1);
        $hash  = $this->totp->hashBackupCode($codes[0]);
        $this->assertTrue($this->totp->verifyBackupCode($codes[0], $hash));
    }

    public function testVerifyBackupCodeReturnsFalseForWrongCode(): void
    {
        $codes = $this->totp->generateBackupCodes(1);
        $hash  = $this->totp->hashBackupCode($codes[0]);
        $this->assertFalse($this->totp->verifyBackupCode('AAAA-BBBB-CCCC', $hash));
    }
}
