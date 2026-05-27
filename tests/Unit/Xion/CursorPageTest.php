<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\CursorPage;
use PHPUnit\Framework\TestCase;

final class CursorPageTest extends TestCase
{
    public function testToArrayShape(): void
    {
        $page = new CursorPage(['a', 'b'], true, 'tok123');
        $arr  = $page->toArray();
        self::assertSame(['a', 'b'], $arr['items']);
        self::assertTrue($arr['has_more']);
        self::assertSame('tok123', $arr['next_cursor']);
    }

    public function testToArrayWithNullCursor(): void
    {
        $page = new CursorPage([], false, null);
        $arr  = $page->toArray();
        self::assertSame([], $arr['items']);
        self::assertFalse($arr['has_more']);
        self::assertNull($arr['next_cursor']);
    }

    public function testImmutable(): void
    {
        $page = new CursorPage(['x'], false, null);
        self::assertSame(['x'], $page->items);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }
}
