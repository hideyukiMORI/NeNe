<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\WebhookSigner;
use PHPUnit\Framework\TestCase;

final class WebhookSignerTest extends TestCase
{
    private const SECRET = 'test-secret-key';
    private const BODY   = '{"event":"order.created","id":42}';

    // ── sign() ────────────────────────────────────────────────────────────────

    public function testSignReturnsCorrectFormat(): void
    {
        $signer = new WebhookSigner(self::SECRET);
        $header = $signer->sign(self::BODY);

        self::assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
    }

    public function testSignUsesProvidedTimestamp(): void
    {
        $signer = new WebhookSigner(self::SECRET);
        $ts     = 1716800000;
        $header = $signer->sign(self::BODY, $ts);

        self::assertStringStartsWith("t={$ts},v1=", $header);
    }

    // ── verify() — happy path ─────────────────────────────────────────────────

    public function testVerifyAcceptsOwnSignature(): void
    {
        $signer = new WebhookSigner(self::SECRET);
        $ts     = time();
        $header = $signer->sign(self::BODY, $ts);

        self::assertTrue($signer->verify(self::BODY, $header, $ts));
    }

    // ── verify() — rejection cases ────────────────────────────────────────────

    public function testVerifyRejectsTamperedBody(): void
    {
        $signer    = new WebhookSigner(self::SECRET);
        $ts        = time();
        $header    = $signer->sign(self::BODY, $ts);
        $badBody   = '{"event":"order.created","id":99}';

        self::assertFalse($signer->verify($badBody, $header, $ts));
    }

    public function testVerifyRejectsStaleTimestamp(): void
    {
        $signer = new WebhookSigner(self::SECRET, 300);
        $ts     = 1716800000;
        $header = $signer->sign(self::BODY, $ts);
        $now    = $ts + 301;  // 1 second past tolerance

        self::assertFalse($signer->verify(self::BODY, $header, $now));
    }

    public function testVerifyRejectsFutureTimestampBeyondTolerance(): void
    {
        $signer = new WebhookSigner(self::SECRET, 300);
        $ts     = 1716800000;
        $header = $signer->sign(self::BODY, $ts);
        $now    = $ts - 301;  // 301 seconds in the past = timestamp is 301s in the future

        self::assertFalse($signer->verify(self::BODY, $header, $now));
    }

    public function testVerifyAcceptsTimestampAtExactTolerance(): void
    {
        $signer = new WebhookSigner(self::SECRET, 300);
        $ts     = 1716800000;
        $header = $signer->sign(self::BODY, $ts);
        $now    = $ts + 300;  // exactly at tolerance boundary

        self::assertTrue($signer->verify(self::BODY, $header, $now));
    }

    public function testVerifyRejectsTimestampOneSecondPastTolerance(): void
    {
        $signer = new WebhookSigner(self::SECRET, 300);
        $ts     = 1716800000;
        $header = $signer->sign(self::BODY, $ts);
        $now    = $ts + 301;  // tolerance + 1

        self::assertFalse($signer->verify(self::BODY, $header, $now));
    }

    public function testVerifyRejectsEmptyHeader(): void
    {
        $signer = new WebhookSigner(self::SECRET);

        self::assertFalse($signer->verify(self::BODY, '', time()));
    }

    public function testVerifyRejectsMalformedHeader(): void
    {
        $signer = new WebhookSigner(self::SECRET);

        self::assertFalse($signer->verify(self::BODY, 'not-a-valid-header', time()));
    }

    public function testVerifyRejectsNonV1SignaturePrefix(): void
    {
        $signer = new WebhookSigner(self::SECRET);
        $ts     = time();
        // Replace v1= with v2= to simulate an unsupported/unknown signature scheme
        $header = $signer->sign(self::BODY, $ts);
        $header = str_replace('v1=', 'v2=', $header);

        self::assertFalse($signer->verify(self::BODY, $header, $ts));
    }

    // ── generateSecret() ──────────────────────────────────────────────────────

    public function testGenerateSecretReturns64CharHex(): void
    {
        $secret = WebhookSigner::generateSecret();

        self::assertSame(64, strlen($secret));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testGenerateSecretProducesDifferentValuesEachCall(): void
    {
        $a = WebhookSigner::generateSecret();
        $b = WebhookSigner::generateSecret();

        self::assertNotSame($a, $b);
    }
}
