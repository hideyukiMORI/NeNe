<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use InvalidArgumentException;
use Nene\Func\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testOfConstructsWithAmountAndCurrency(): void
    {
        $m = Money::of(500, 'USD');
        self::assertSame(500, $m->amount());
        self::assertSame('USD', $m->currency());
    }

    public function testZeroReturnsZeroAmount(): void
    {
        $m = Money::zero('EUR');
        self::assertSame(0, $m->amount());
        self::assertSame('EUR', $m->currency());
    }

    public function testAddReturnsSum(): void
    {
        $a = Money::of(300, 'JPY');
        $b = Money::of(200, 'JPY');
        $result = $a->add($b);
        self::assertSame(500, $result->amount());
        self::assertSame('JPY', $result->currency());
    }

    public function testSubtractReturnsDifference(): void
    {
        $a = Money::of(1000, 'JPY');
        $b = Money::of(400, 'JPY');
        $result = $a->subtract($b);
        self::assertSame(600, $result->amount());
    }

    public function testMultiplyByInteger(): void
    {
        $m = Money::of(250, 'JPY');
        $result = $m->multiply(4);
        self::assertSame(1000, $result->amount());
    }

    public function testMultiplyByFloat(): void
    {
        // 1000 * 0.1 = 100.0 — exact in float, truncated to int
        $m = Money::of(1000, 'JPY');
        $result = $m->multiply(0.1);
        self::assertSame(100, $result->amount());
    }

    public function testRoundAfterMultiply(): void
    {
        // 1000 * 0.108 = 108.0 — floating-point is actually 107.99999..., truncated to 107
        // round() brings it back to 108
        $m = Money::of(1000, 'JPY');
        $result = $m->multiply(0.108)->round();
        self::assertSame(108, $result->amount());
    }

    public function testNegate(): void
    {
        $m = Money::of(500, 'JPY');
        $neg = $m->negate();
        self::assertSame(-500, $neg->amount());
        self::assertSame('JPY', $neg->currency());
    }

    public function testAbsOnNegative(): void
    {
        $m = Money::of(-300, 'JPY');
        $result = $m->abs();
        self::assertSame(300, $result->amount());
    }

    public function testAbsOnPositive(): void
    {
        $m = Money::of(300, 'JPY');
        $result = $m->abs();
        self::assertSame(300, $result->amount());
    }

    public function testIsZeroTrue(): void
    {
        $m = Money::zero('JPY');
        self::assertTrue($m->isZero());
    }

    public function testIsZeroFalse(): void
    {
        $m = Money::of(1, 'JPY');
        self::assertFalse($m->isZero());
    }

    public function testIsPositiveTrue(): void
    {
        $m = Money::of(100, 'JPY');
        self::assertTrue($m->isPositive());
    }

    public function testIsNegativeTrue(): void
    {
        $m = Money::of(-50, 'JPY');
        self::assertTrue($m->isNegative());
    }

    public function testEqualsTrue(): void
    {
        $a = Money::of(100, 'JPY');
        $b = Money::of(100, 'JPY');
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseAmountDiffers(): void
    {
        $a = Money::of(100, 'JPY');
        $b = Money::of(200, 'JPY');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseCurrencyDiffers(): void
    {
        $a = Money::of(100, 'JPY');
        $b = Money::of(100, 'USD');
        self::assertFalse($a->equals($b));
    }

    public function testCompareToLessThan(): void
    {
        $a = Money::of(50, 'JPY');
        $b = Money::of(100, 'JPY');
        self::assertSame(-1, $a->compareTo($b));
    }

    public function testCompareToEqual(): void
    {
        $a = Money::of(100, 'JPY');
        $b = Money::of(100, 'JPY');
        self::assertSame(0, $a->compareTo($b));
    }

    public function testCompareToGreaterThan(): void
    {
        $a = Money::of(200, 'JPY');
        $b = Money::of(100, 'JPY');
        self::assertSame(1, $a->compareTo($b));
    }

    public function testAddThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch: JPY vs USD');
        Money::of(100, 'JPY')->add(Money::of(100, 'USD'));
    }

    public function testSubtractThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch: EUR vs GBP');
        Money::of(100, 'EUR')->subtract(Money::of(100, 'GBP'));
    }

    public function testCompareToThrowsOnCurrencyMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'JPY')->compareTo(Money::of(100, 'EUR'));
    }

    public function testToArrayStructure(): void
    {
        $m = Money::of(1500, 'USD');
        $arr = $m->toArray();
        self::assertSame(['amount' => 1500, 'currency' => 'USD'], $arr);
    }

    public function testFormatJPY(): void
    {
        self::assertSame('¥1,500', Money::of(1500, 'JPY')->format());
    }

    public function testFormatUSD(): void
    {
        self::assertSame('$1.50', Money::of(150, 'USD')->format());
    }

    public function testFormatUnknownCurrency(): void
    {
        self::assertSame('100 XYZ', Money::of(100, 'XYZ')->format());
    }

    public function testImmutabilityAfterAdd(): void
    {
        $original = Money::of(1000, 'JPY');
        $original->add(Money::of(500, 'JPY'));
        self::assertSame(1000, $original->amount());
    }
}
