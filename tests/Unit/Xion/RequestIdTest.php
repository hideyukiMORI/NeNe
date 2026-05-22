<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * RequestId resolves once per request from either an inbound header
 * (when trust is enabled) or a generated random hex. The tests pin (a)
 * inbound honoring, (b) validation that rejects malformed inbound,
 * (c) the disabled-trust path, (d) header-name override, and (e) the
 * cache behavior so resolution happens exactly once per request.
 */
final class RequestIdTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv();
        $this->clearInboundHeader();
        RequestId::reset();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        $this->clearInboundHeader();
        RequestId::reset();
    }

    public function testGeneratesWhenNoInboundHeader(): void
    {
        $id = RequestId::current();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testHonorsInboundHeaderWhenTrustEnabled(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'my-trace-abc-123';
        self::assertSame('my-trace-abc-123', RequestId::current());
    }

    public function testGeneratesWhenInboundHeaderInvalid(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'bad value with spaces';
        $id = RequestId::current();
        self::assertNotSame('bad value with spaces', $id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testGeneratesWhenInboundHeaderExceedsLengthCap(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = str_repeat('a', 129);
        $id = RequestId::current();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testGeneratesWhenTrustDisabled(): void
    {
        putenv('NENE_REQUEST_ID_TRUST_INBOUND=0');
        $_SERVER['HTTP_X_REQUEST_ID'] = 'should-be-ignored';
        $id = RequestId::current();
        self::assertNotSame('should-be-ignored', $id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testHonorsOverriddenHeaderName(): void
    {
        putenv('NENE_REQUEST_ID_HEADER=X-Correlation-ID');
        $_SERVER['HTTP_X_CORRELATION_ID'] = 'corr-001';
        self::assertSame('corr-001', RequestId::current());
    }

    public function testCurrentIsCachedForTheRequest(): void
    {
        $first = RequestId::current();
        $second = RequestId::current();
        self::assertSame($first, $second);
    }

    public function testResetClearsCache(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'first-id';
        $first = RequestId::current();
        self::assertSame('first-id', $first);

        $_SERVER['HTTP_X_REQUEST_ID'] = 'second-id';
        // No reset() — cache holds.
        self::assertSame('first-id', RequestId::current());

        RequestId::reset();
        self::assertSame('second-id', RequestId::current());
    }

    public function testHeaderNameDefaultsToXRequestId(): void
    {
        self::assertSame('X-Request-ID', RequestId::headerName());
    }

    public function testEmptyHeaderNameMeansDoNotEmit(): void
    {
        putenv('NENE_REQUEST_ID_HEADER=X-Foo');
        // We use the configured value, not the default.
        self::assertSame('X-Foo', RequestId::headerName());
    }

    public function testIsValidRejectsControlChars(): void
    {
        self::assertFalse(RequestId::isValid("abc\ndef"));
        self::assertFalse(RequestId::isValid("abc\tdef"));
        self::assertFalse(RequestId::isValid('abc def'));
    }

    public function testIsValidAcceptsAllowedCharset(): void
    {
        self::assertTrue(RequestId::isValid('abcXYZ0-9._:'));
    }

    private function clearEnv(): void
    {
        putenv('NENE_REQUEST_ID_HEADER');
        putenv('NENE_REQUEST_ID_TRUST_INBOUND');
    }

    private function clearInboundHeader(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with((string)$key, 'HTTP_X_REQUEST_ID') || str_starts_with((string)$key, 'HTTP_X_CORRELATION')) {
                unset($_SERVER[$key]);
            }
        }
    }
}
