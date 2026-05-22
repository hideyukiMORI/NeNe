<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\BearerAuth;
use PHPUnit\Framework\TestCase;

/**
 * BearerAuth.extractToken is the pure-static parse step (validated
 * without a DB), so this unit suite focuses on it. End-to-end
 * authentication (env token comparison + UserMapper lookup + AuthSession
 * binding) is exercised by the HTTP smoke tests because they require
 * a running database.
 */
final class BearerAuthTest extends TestCase
{
    public function testExtractsTokenFromBearerHeader(): void
    {
        self::assertSame('abc-123', BearerAuth::extractToken('Bearer abc-123'));
    }

    public function testIsCaseInsensitiveOnSchemeKeyword(): void
    {
        self::assertSame('abc-123', BearerAuth::extractToken('bearer abc-123'));
        self::assertSame('abc-123', BearerAuth::extractToken('BEARER abc-123'));
    }

    public function testAcceptsMultipleWhitespaceBetweenSchemeAndToken(): void
    {
        self::assertSame('abc-123', BearerAuth::extractToken('Bearer  abc-123'));
        self::assertSame('abc-123', BearerAuth::extractToken("Bearer\tabc-123"));
    }

    public function testIgnoresTrailingWhitespace(): void
    {
        self::assertSame('abc-123', BearerAuth::extractToken('Bearer abc-123 '));
        self::assertSame('abc-123', BearerAuth::extractToken("Bearer abc-123\n"));
    }

    public function testRejectsEmptyHeader(): void
    {
        self::assertNull(BearerAuth::extractToken(''));
    }

    public function testRejectsBasicAuth(): void
    {
        self::assertNull(BearerAuth::extractToken('Basic dXNlcjpwYXNz'));
    }

    public function testRejectsBearerWithoutToken(): void
    {
        self::assertNull(BearerAuth::extractToken('Bearer'));
        self::assertNull(BearerAuth::extractToken('Bearer '));
    }

    public function testRejectsWhitespaceInsideToken(): void
    {
        // Tokens MUST be a single non-whitespace run after the scheme.
        self::assertNull(BearerAuth::extractToken('Bearer abc def'));
    }

    public function testResetIsIdempotent(): void
    {
        BearerAuth::reset();
        BearerAuth::reset();
        self::assertTrue(true);
    }
}
