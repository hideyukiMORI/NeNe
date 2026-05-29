<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Cors;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nene\Xion\Cors (FT45).
 *
 * header() is a no-op in CLI; test only isAllowed() (pure logic) and
 * smoke-test sendHeaders() to confirm no exceptions are thrown.
 * The OPTIONS path of handlePreflight() is not tested as it calls exit().
 */
final class CorsTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // isAllowed()
    // ------------------------------------------------------------------ //

    public function testIsAllowedReturnsTrueForWildcard(): void
    {
        self::assertTrue(Cors::isAllowed('https://any.example.com', ['*']));
        self::assertTrue(Cors::isAllowed('https://evil.example.com', ['*']));
        self::assertTrue(Cors::isAllowed('http://localhost:3000', ['*']));
    }

    public function testIsAllowedReturnsTrueForExactMatch(): void
    {
        self::assertTrue(Cors::isAllowed(
            'https://app.example.com',
            ['https://app.example.com'],
        ));
    }

    public function testIsAllowedReturnsFalseForUnknownOrigin(): void
    {
        self::assertFalse(Cors::isAllowed(
            'https://evil.example.com',
            ['https://app.example.com', 'https://admin.example.com'],
        ));
    }

    public function testIsAllowedIsCaseInsensitive(): void
    {
        self::assertTrue(Cors::isAllowed(
            'HTTPS://APP.EXAMPLE.COM',
            ['https://app.example.com'],
        ));
        self::assertTrue(Cors::isAllowed(
            'https://app.example.com',
            ['HTTPS://APP.EXAMPLE.COM'],
        ));
    }

    public function testIsAllowedReturnsFalseForEmptyList(): void
    {
        self::assertFalse(Cors::isAllowed('https://app.example.com', []));
    }

    public function testIsAllowedReturnsTrueForOneOfMultiple(): void
    {
        $allowedOrigins = [
            'https://app.example.com',
            'https://admin.example.com',
            'https://dashboard.example.com',
        ];
        self::assertTrue(Cors::isAllowed('https://admin.example.com', $allowedOrigins));
    }

    // ------------------------------------------------------------------ //
    // sendHeaders() — smoke tests (header() is a no-op in CLI)
    // ------------------------------------------------------------------ //

    public function testSendHeadersWithNullOriginDoesNotThrow(): void
    {
        // When origin is null and HTTP_ORIGIN is not set, method is a no-op.
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            origin: null,
        );
        self::assertTrue(true);
    }

    public function testSendHeadersWithWildcardDoesNotThrow(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['*'],
            origin: 'https://any.example.com',
        );
        self::assertTrue(true);
    }

    public function testSendHeadersWithAllowedOriginDoesNotThrow(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            allowedMethods: ['GET', 'POST', 'PUT', 'DELETE'],
            allowedHeaders: ['Content-Type', 'X-CSRF-Token'],
            origin: 'https://app.example.com',
        );
        self::assertTrue(true);
    }

    public function testSendHeadersWithUnmatchedOriginDoesNotThrow(): void
    {
        // When origin is not in the allow-list, headers are simply not sent — no exception.
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            origin: 'https://evil.example.com',
        );
        self::assertTrue(true);
    }

    public function testSendHeadersWithCredentialsTrueDoesNotThrow(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            credentials: true,
            origin: 'https://app.example.com',
        );
        self::assertTrue(true);
    }

    public function testSendHeadersWithExposeHeadersDoesNotThrow(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            exposeHeaders: ['X-Request-Id', 'X-Rate-Limit-Remaining'],
            origin: 'https://app.example.com',
        );
        self::assertTrue(true);
    }

    // ------------------------------------------------------------------ //
    // handlePreflight()
    // ------------------------------------------------------------------ //

    public function testHandlePreflightIsNoOpForGet(): void
    {
        // Must not call exit(). Simply returns when method is GET.
        Cors::handlePreflight('GET');
        self::assertTrue(true);
    }

    public function testHandlePreflightIsNoOpForPost(): void
    {
        Cors::handlePreflight('POST');
        self::assertTrue(true);
    }

    public function testHandlePreflightIsNoOpForPut(): void
    {
        Cors::handlePreflight('PUT');
        self::assertTrue(true);
    }

    public function testHandlePreflightIsNoOpForDelete(): void
    {
        Cors::handlePreflight('DELETE');
        self::assertTrue(true);
    }
}
