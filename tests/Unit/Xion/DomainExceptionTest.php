<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class DomainExceptionTest extends TestCase
{
    public function testCarriesTheErrorCodeProvidedAtConstruction(): void
    {
        $exception = new DomainException('BOOKMARK-NOT-FOUND');

        self::assertSame('BOOKMARK-NOT-FOUND', $exception->errorCode());
        self::assertSame('BOOKMARK-NOT-FOUND', $exception->getMessage());
    }

    public function testIsAStandardThrowable(): void
    {
        $exception = new DomainException('TAG-NAME-DUPLICATE');

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
