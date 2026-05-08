<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testSanitizeJsonpCallbackAllowsIdentifierPaths(): void
    {
        self::assertSame('jsonCallback', Json::sanitizeJsonpCallback('jsonCallback'));
        self::assertSame('App.callbacks.done', Json::sanitizeJsonpCallback('App.callbacks.done'));
        self::assertSame('$callback._done', Json::sanitizeJsonpCallback('$callback._done'));
    }

    public function testSanitizeJsonpCallbackRejectsExecutableInput(): void
    {
        self::assertSame('jsonCallback', Json::sanitizeJsonpCallback('alert(1)'));
        self::assertSame('jsonCallback', Json::sanitizeJsonpCallback('foo;alert(1)'));
        self::assertSame('jsonCallback', Json::sanitizeJsonpCallback('foo["bar"]'));
        self::assertSame('jsonCallback', Json::sanitizeJsonpCallback(''));
    }
}
