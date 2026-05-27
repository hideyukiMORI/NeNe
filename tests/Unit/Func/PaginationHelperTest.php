<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\PaginationHelper;
use PHPUnit\Framework\TestCase;

final class PaginationHelperTest extends TestCase
{
    public function testOffsetPageOne(): void
    {
        self::assertSame(0, PaginationHelper::offset(1, 10));
    }

    public function testOffsetPageTwo(): void
    {
        self::assertSame(10, PaginationHelper::offset(2, 10));
    }

    public function testOffsetNeverNegative(): void
    {
        self::assertSame(0, PaginationHelper::offset(0, 10));
        self::assertSame(0, PaginationHelper::offset(-5, 10));
    }

    public function testTotalPagesExact(): void
    {
        self::assertSame(10, PaginationHelper::totalPages(100, 10));
    }

    public function testTotalPagesRoundsUp(): void
    {
        self::assertSame(11, PaginationHelper::totalPages(101, 10));
    }

    public function testTotalPagesZeroTotal(): void
    {
        self::assertSame(0, PaginationHelper::totalPages(0, 10));
    }

    public function testTotalPagesZeroPerPage(): void
    {
        self::assertSame(0, PaginationHelper::totalPages(100, 0));
    }

    public function testClampBelowMin(): void
    {
        self::assertSame(1, PaginationHelper::clamp(0, 100, 10));
    }

    public function testClampAboveMax(): void
    {
        self::assertSame(10, PaginationHelper::clamp(99, 100, 10));
    }

    public function testClampWithinRange(): void
    {
        self::assertSame(3, PaginationHelper::clamp(3, 100, 10));
    }

    public function testClampZeroPages(): void
    {
        self::assertSame(1, PaginationHelper::clamp(5, 0, 10));
    }

    public function testWindowMiddlePage(): void
    {
        $result = PaginationHelper::window(5, 10, 2);
        // Should contain 3,4,5,6,7 consecutively (no 0s in the middle)
        self::assertContains(3, $result);
        self::assertContains(4, $result);
        self::assertContains(5, $result);
        self::assertContains(6, $result);
        self::assertContains(7, $result);
        // Verify no 0 between 3 and 7 (middle of window)
        $middleSlice = array_slice($result, array_search(3, $result), 5);
        self::assertSame([3, 4, 5, 6, 7], $middleSlice);
    }

    public function testWindowFirstPage(): void
    {
        // window(1, 10, 2) — no leading ellipsis, trailing ellipsis before 10
        $result = PaginationHelper::window(1, 10, 2);
        self::assertSame([1, 2, 3, 0, 10], $result);
    }

    public function testWindowLastPage(): void
    {
        // window(10, 10, 2) — leading ellipsis after 1, no trailing ellipsis
        $result = PaginationHelper::window(10, 10, 2);
        self::assertSame([1, 0, 8, 9, 10], $result);
    }

    public function testWindowSmallTotal(): void
    {
        // window(1, 3, 2) — all 3 pages fit in window, no ellipses
        $result = PaginationHelper::window(1, 3, 2);
        self::assertSame([1, 2, 3], $result);
    }

    public function testWindowEmptyWhenNoPages(): void
    {
        self::assertSame([], PaginationHelper::window(1, 0, 2));
    }
}
