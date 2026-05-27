<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    // ------------------------------------------------------------------
    // escapeLike
    // ------------------------------------------------------------------

    public function testEscapeLikeSpecialChars(): void
    {
        self::assertSame('\\%foo\\_bar\\\\', SearchQuery::escapeLike('%foo_bar\\'));
    }

    public function testEscapeLikePlainString(): void
    {
        self::assertSame('hello', SearchQuery::escapeLike('hello'));
    }

    public function testEscapeLikeEmptyString(): void
    {
        self::assertSame('', SearchQuery::escapeLike(''));
    }

    // ------------------------------------------------------------------
    // likePattern
    // ------------------------------------------------------------------

    public function testLikePatternContains(): void
    {
        self::assertSame('%hello%', SearchQuery::likePattern('hello'));
    }

    public function testLikePatternContainsExplicit(): void
    {
        self::assertSame('%hello%', SearchQuery::likePattern('hello', 'contains'));
    }

    public function testLikePatternStartsWith(): void
    {
        self::assertSame('hello%', SearchQuery::likePattern('hello', 'starts-with'));
    }

    public function testLikePatternEndsWith(): void
    {
        self::assertSame('%hello', SearchQuery::likePattern('hello', 'ends-with'));
    }

    public function testLikePatternEscapesSpecialCharsInContains(): void
    {
        self::assertSame('%100\\%%', SearchQuery::likePattern('100%'));
    }

    public function testLikePatternUnknownModeFallsBackToContains(): void
    {
        self::assertSame('%hello%', SearchQuery::likePattern('hello', 'bogus'));
    }

    // ------------------------------------------------------------------
    // sanitizeFts
    // ------------------------------------------------------------------

    public function testSanitizeFtsRemovesDoubleQuotes(): void
    {
        self::assertSame('unclosed', SearchQuery::sanitizeFts('"unclosed'));
    }

    public function testSanitizeFtsTrimsWhitespace(): void
    {
        self::assertSame('hello', SearchQuery::sanitizeFts('  hello  '));
    }

    public function testSanitizeFtsRemovesAllQuotes(): void
    {
        self::assertSame('phrase search', SearchQuery::sanitizeFts('"phrase search"'));
    }

    public function testSanitizeFtsPlainString(): void
    {
        self::assertSame('hello world', SearchQuery::sanitizeFts('hello world'));
    }

    public function testSanitizeFtsEmptyString(): void
    {
        self::assertSame('', SearchQuery::sanitizeFts(''));
    }

    // ------------------------------------------------------------------
    // normalize
    // ------------------------------------------------------------------

    public function testNormalizeRemovesNullBytes(): void
    {
        self::assertSame('hello world', SearchQuery::normalize("hello\0world"));
    }

    public function testNormalizeCollapsesWhitespace(): void
    {
        self::assertSame('hello world', SearchQuery::normalize("hello  world"));
    }

    public function testNormalizeTrimsLeadingTrailing(): void
    {
        self::assertSame('hello', SearchQuery::normalize('  hello  '));
    }

    public function testNormalizeCollapsesTabsAndNewlines(): void
    {
        self::assertSame('a b c', SearchQuery::normalize("a\t\nb\r\nc"));
    }

    public function testNormalizeEmptyString(): void
    {
        self::assertSame('', SearchQuery::normalize(''));
    }
}
