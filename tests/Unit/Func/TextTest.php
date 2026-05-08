<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testGetLinkReturnsTextWhenLinkIsEmpty(): void
    {
        self::assertSame('NeNe', Text::getLink('NeNe', ''));
    }

    public function testGetLinkReturnsAnchorWhenLinkIsPresent(): void
    {
        self::assertSame(
            '<a href="https://example.com" >NeNe</a>',
            Text::getLink('NeNe', 'https://example.com')
        );
    }

    public function testGetLinkAddsBlankTargetAttributes(): void
    {
        self::assertSame(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer" >NeNe</a>',
            Text::getLink('NeNe', 'https://example.com', true)
        );
    }
}
