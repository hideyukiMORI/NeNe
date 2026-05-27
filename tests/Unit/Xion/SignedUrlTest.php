<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SignedUrl;
use PHPUnit\Framework\TestCase;

final class SignedUrlTest extends TestCase
{
    private SignedUrl $signer;
    private int $now;

    protected function setUp(): void
    {
        $this->signer = new SignedUrl('test-secret-key', 3600);
        $this->now    = 1700000000; // fixed base timestamp for deterministic tests
    }

    public function testSignProducesUrlWithExpiresAndSignatureParams(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], null, $this->now);

        self::assertStringContainsString('/download/invoice?', $url);
        self::assertStringContainsString('expires=', $url);
        self::assertStringContainsString('signature=', $url);
        self::assertStringContainsString('id=42', $url);
    }

    public function testVerifyReturnsTrueForValidUrl(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], null, $this->now);

        // Verify at exactly now (not expired)
        self::assertTrue($this->signer->verify($url, $this->now));
    }

    public function testVerifyReturnsFalseForExpiredUrl(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], 3600, $this->now);

        // Verify past expiry
        self::assertFalse($this->signer->verify($url, $this->now + 3601));
    }

    public function testVerifyReturnsFalseForTamperedSignature(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], null, $this->now);

        // Replace signature with garbage
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef00000000', $url);
        self::assertNotNull($tampered);
        self::assertFalse($this->signer->verify($tampered, $this->now));
    }

    public function testVerifyReturnsFalseForMissingSignatureParam(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], null, $this->now);

        // Strip the signature parameter
        $noSig = preg_replace('/&signature=[^&]+$/', '', $url);
        self::assertNotNull($noSig);
        self::assertFalse($this->signer->verify($noSig, $this->now));
    }

    public function testVerifyReturnsFalseForMissingExpiresParam(): void
    {
        // Construct a URL without expires
        $url = '/download/invoice?id=42&signature=abc123';
        self::assertFalse($this->signer->verify($url, $this->now));
    }

    public function testVerifyReturnsFalseForUrlWithoutQueryString(): void
    {
        self::assertFalse($this->signer->verify('/download/invoice', $this->now));
    }

    public function testVerifyReturnsFalseForTamperedExpiry(): void
    {
        $url = $this->signer->sign('/download/invoice', ['id' => 42], 3600, $this->now);

        // Push the expiry far into the future — signature will no longer match
        $tampered = preg_replace('/expires=\d+/', 'expires=9999999999', $url);
        self::assertNotNull($tampered);
        self::assertFalse($this->signer->verify($tampered, $this->now));
    }

    public function testSignAndVerifyRoundTripWithExtraParams(): void
    {
        $params = ['user_id' => 7, 'token' => 'abc', 'action' => 'confirm'];
        $url    = $this->signer->sign('/email/confirm', $params, 86400, $this->now);

        self::assertTrue($this->signer->verify($url, $this->now));
        self::assertTrue($this->signer->verify($url, $this->now + 86399));
        self::assertFalse($this->signer->verify($url, $this->now + 86401));
    }

    public function testCustomTtlIsRespected(): void
    {
        $url = $this->signer->sign('/reset', [], 60, $this->now);

        // Verify at now+30 (within TTL)
        self::assertTrue($this->signer->verify($url, $this->now + 30));

        // Verify at now+90 (past TTL)
        self::assertFalse($this->signer->verify($url, $this->now + 90));
    }

    public function testRequireValidThrowsOnExpiredUrl(): void
    {
        $url = $this->signer->sign('/reset', [], 60, $this->now);

        $this->expectException(\Throwable::class);
        $this->signer->requireValid($url, $this->now + 90);
    }

    public function testRequireValidThrowsOnInvalidSignature(): void
    {
        $url = $this->signer->sign('/reset', [], 60, $this->now);

        // Tamper with signature
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url);
        self::assertNotNull($tampered);

        $this->expectException(\Throwable::class);
        $this->signer->requireValid($tampered, $this->now);
    }

    public function testRequireValidDoesNotThrowForValidUrl(): void
    {
        $url = $this->signer->sign('/reset', [], 3600, $this->now);

        // Should not throw
        $this->signer->requireValid($url, $this->now + 1800);
        $this->addToAssertionCount(1);
    }

    public function testGenerateSecretReturns64CharHex(): void
    {
        $secret = SignedUrl::generateSecret();

        self::assertSame(64, strlen($secret));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testDefaultTtlApplied(): void
    {
        // Default TTL is 3600 seconds
        $url = $this->signer->sign('/download', [], null, $this->now);

        // 1 second before expiry — valid
        self::assertTrue($this->signer->verify($url, $this->now + 3599));

        // 1 second after expiry — expired
        self::assertFalse($this->signer->verify($url, $this->now + 3601));
    }

    public function testRequireValidThrowsHttp410ForExpiredUrl(): void
    {
        $url = $this->signer->sign('/reset', [], 60, $this->now);

        try {
            $this->signer->requireValid($url, $this->now + 90);
            self::fail('Expected HttpTermination to be thrown');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\Nene\Xion\HttpTermination::class, $e);
            \assert($e instanceof \Nene\Xion\HttpTermination);
            self::assertSame(410, $e->response()->statusCode());
        }
    }

    public function testRequireValidThrowsHttp403ForInvalidSignature(): void
    {
        $tampered = '/reset?expires=' . ($this->now + 3600) . '&signature=invalid';

        try {
            $this->signer->requireValid($tampered, $this->now);
            self::fail('Expected HttpTermination to be thrown');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\Nene\Xion\HttpTermination::class, $e);
            \assert($e instanceof \Nene\Xion\HttpTermination);
            self::assertSame(403, $e->response()->statusCode());
        }
    }
}
