<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Cursor;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cursor  = new Cursor('2026-05-27 12:00:00', 42);
        $token   = $cursor->encode();
        $decoded = Cursor::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('2026-05-27 12:00:00', $decoded->createdAt);
        self::assertSame(42, $decoded->id);
    }

    public function testDecodeReturnsNullOnEmpty(): void
    {
        self::assertNull(Cursor::decode(''));
    }

    public function testDecodeReturnsNullOnGarbage(): void
    {
        self::assertNull(Cursor::decode('not-valid-base64url!!!'));
    }

    public function testDecodeReturnsNullOnMissingFields(): void
    {
        $token = strtr(base64_encode('{"x":1}'), '+/', '-_');
        self::assertNull(Cursor::decode($token));
    }

    public function testDecodeReturnsNullOnWrongTypes(): void
    {
        $token = strtr(base64_encode('{"c":123,"i":"not-int"}'), '+/', '-_');
        self::assertNull(Cursor::decode($token));
    }

    public function testEncodeIsUrlSafe(): void
    {
        $cursor = new Cursor('2026-05-27 12:00:00', 42);
        $token  = $cursor->encode();
        self::assertStringNotContainsString('+', $token);
        self::assertStringNotContainsString('/', $token);
    }
}
