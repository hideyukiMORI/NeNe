<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\HttpResponse;
use Nene\Xion\ResponseDecorator;
use PHPUnit\Framework\TestCase;

/**
 * ResponseDecorator is the chokepoint that closes FT7 F-6 / FT8 F-4.
 * Tests pin (a) the always-on safe default, (b) opt-in env headers,
 * (c) the controller-wins precedence, and (d) the `reset()` contract
 * that lets the test rebuild from a different env between assertions.
 */
final class ResponseDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv();
        ResponseDecorator::reset();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        ResponseDecorator::reset();
    }

    public function testDecorateAlwaysAddsXContentTypeOptions(): void
    {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json'], '{"ok":true}');
        $decorated = ResponseDecorator::decorate($response);

        self::assertSame('nosniff', $decorated->headers()['X-Content-Type-Options']);
        self::assertSame('application/json', $decorated->headers()['Content-Type']);
    }

    public function testDecorateAddsCspWhenEnvIsSet(): void
    {
        putenv("NENE_SECURITY_CSP=default-src 'self'");
        ResponseDecorator::reset();

        $decorated = ResponseDecorator::decorate(new HttpResponse(200, [], ''));

        self::assertSame("default-src 'self'", $decorated->headers()['Content-Security-Policy']);
    }

    public function testDecorateAddsHstsWhenEnvIsSet(): void
    {
        putenv('NENE_SECURITY_HSTS=max-age=31536000; includeSubDomains');
        ResponseDecorator::reset();

        $decorated = ResponseDecorator::decorate(new HttpResponse(200, [], ''));

        self::assertSame('max-age=31536000; includeSubDomains', $decorated->headers()['Strict-Transport-Security']);
    }

    public function testExistingHeaderWinsOverDecoratorHeader(): void
    {
        putenv('NENE_SECURITY_FRAME_OPTIONS=DENY');
        ResponseDecorator::reset();

        $response = new HttpResponse(200, ['X-Frame-Options' => 'SAMEORIGIN'], '');
        $decorated = ResponseDecorator::decorate($response);

        self::assertSame('SAMEORIGIN', $decorated->headers()['X-Frame-Options']);
    }

    public function testHeaderNameMatchIsCaseInsensitive(): void
    {
        putenv('NENE_SECURITY_FRAME_OPTIONS=DENY');
        ResponseDecorator::reset();

        // The controller wrote `x-frame-options` lowercase; decorator must respect it.
        $response = new HttpResponse(200, ['x-frame-options' => 'ALLOW-FROM https://example.com'], '');
        $decorated = ResponseDecorator::decorate($response);

        self::assertArrayNotHasKey('X-Frame-Options', $decorated->headers());
        self::assertSame('ALLOW-FROM https://example.com', $decorated->headers()['x-frame-options']);
    }

    public function testResetClearsCachedHeaders(): void
    {
        putenv('NENE_SECURITY_CSP=first');
        ResponseDecorator::reset();
        $first = ResponseDecorator::headers();
        self::assertSame('first', $first['Content-Security-Policy']);

        putenv('NENE_SECURITY_CSP=second');
        // No reset() — cache holds.
        self::assertSame('first', ResponseDecorator::headers()['Content-Security-Policy']);

        ResponseDecorator::reset();
        self::assertSame('second', ResponseDecorator::headers()['Content-Security-Policy']);
    }

    public function testStatusCodeAndBodyArePreserved(): void
    {
        $response = new HttpResponse(404, ['Content-Type' => 'text/html'], '<html>missing</html>');
        $decorated = ResponseDecorator::decorate($response);

        self::assertSame(404, $decorated->statusCode());
        self::assertSame('<html>missing</html>', $decorated->body());
    }

    // ------------------------------------------------------------------ //
    // Server-Timing integration (FT20)
    // ------------------------------------------------------------------ //

    public function testServerTimingHeaderAbsentWhenDisabled(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=0');
        $headers = ResponseDecorator::headers();

        self::assertArrayNotHasKey('Server-Timing', $headers);
    }

    public function testServerTimingHeaderPresentWhenEnabled(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=1');
        \Nene\Xion\ServerTiming::start();
        $headers = ResponseDecorator::headers();

        self::assertArrayHasKey('Server-Timing', $headers);
        self::assertStringStartsWith('app;dur=', $headers['Server-Timing']);
    }

    public function testServerTimingHeaderNotOverriddenByController(): void
    {
        // Controller sets its own Server-Timing; decorator must not overwrite it.
        putenv('NENE_SERVER_TIMING_ENABLED=1');
        \Nene\Xion\ServerTiming::start();

        $response = new HttpResponse(200, ['Server-Timing' => 'db;dur=5'], '');
        $decorated = ResponseDecorator::decorate($response);

        self::assertSame('db;dur=5', $decorated->headers()['Server-Timing']);
    }

    private function clearEnv(): void
    {
        foreach ([
            'NENE_SECURITY_CSP',
            'NENE_SECURITY_FRAME_OPTIONS',
            'NENE_SECURITY_REFERRER_POLICY',
            'NENE_SECURITY_HSTS',
            'NENE_SECURITY_PERMISSIONS_POLICY',
            'NENE_SERVER_TIMING_ENABLED',
        ] as $name) {
            putenv($name);
        }
        \Nene\Xion\ServerTiming::reset();
    }
}
