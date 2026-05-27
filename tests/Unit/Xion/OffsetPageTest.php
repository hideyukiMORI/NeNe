<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\OffsetPage;
use PHPUnit\Framework\TestCase;

final class OffsetPageTest extends TestCase
{
    public function testTotalPagesCalculation(): void
    {
        $page = new OffsetPage([], 100, 1, 10);
        self::assertSame(10, $page->totalPages);
    }

    public function testTotalPagesRoundsUp(): void
    {
        $page = new OffsetPage([], 101, 1, 10);
        self::assertSame(11, $page->totalPages);
    }

    public function testTotalPagesZeroTotal(): void
    {
        $page = new OffsetPage([], 0, 1, 10);
        self::assertSame(0, $page->totalPages);
    }

    public function testHasPrevFalseOnFirstPage(): void
    {
        $page = new OffsetPage([], 100, 1, 10);
        self::assertFalse($page->hasPrev);
    }

    public function testHasPrevTrueOnSecondPage(): void
    {
        $page = new OffsetPage([], 100, 2, 10);
        self::assertTrue($page->hasPrev);
    }

    public function testHasNextTrueWhenMorePages(): void
    {
        $page = new OffsetPage([], 100, 1, 10);
        self::assertTrue($page->hasNext);
    }

    public function testHasNextFalseOnLastPage(): void
    {
        $page = new OffsetPage([], 100, 10, 10);
        self::assertFalse($page->hasNext);
    }

    public function testHasNextFalseWhenSinglePage(): void
    {
        $page = new OffsetPage([], 5, 1, 10);
        self::assertFalse($page->hasNext);
    }

    public function testToArrayStructure(): void
    {
        $page = new OffsetPage([], 10, 1, 10);
        $arr  = $page->toArray();
        self::assertArrayHasKey('items', $arr);
        self::assertArrayHasKey('pagination', $arr);
    }

    public function testToArrayPaginationKeys(): void
    {
        $page       = new OffsetPage([], 10, 1, 10);
        $pagination = $page->toArray()['pagination'];
        self::assertArrayHasKey('page', $pagination);
        self::assertArrayHasKey('per_page', $pagination);
        self::assertArrayHasKey('total', $pagination);
        self::assertArrayHasKey('total_pages', $pagination);
        self::assertArrayHasKey('has_prev', $pagination);
        self::assertArrayHasKey('has_next', $pagination);
    }

    public function testItemsPreserved(): void
    {
        $items = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $page  = new OffsetPage($items, 2, 1, 10);
        self::assertSame($items, $page->items);
        self::assertSame($items, $page->toArray()['items']);
    }

    public function testFirstPageWithFullResults(): void
    {
        $items = array_fill(0, 20, ['id' => 1]);
        $page  = new OffsetPage($items, 20, 1, 20);
        self::assertFalse($page->hasNext);
        self::assertFalse($page->hasPrev);
        self::assertSame(1, $page->totalPages);
    }

    public function testMiddlePage(): void
    {
        $page = new OffsetPage([], 30, 2, 10);
        self::assertTrue($page->hasPrev);
        self::assertTrue($page->hasNext);
    }

    public function testSingleItemOnLastPage(): void
    {
        // total=21, perPage=10, page=3: totalPages=3, hasNext=false, hasPrev=true
        $page = new OffsetPage([], 21, 3, 10);
        self::assertFalse($page->hasNext);
        self::assertTrue($page->hasPrev);
        self::assertSame(3, $page->totalPages);
    }
}
