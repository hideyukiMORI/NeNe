<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\OptimisticLock;
use Nene\Xion\HttpTermination;
use PHPUnit\Framework\TestCase;

final class OptimisticLockTest extends TestCase
{
    // -----------------------------------------------------------------
    // parseIfMatch
    // -----------------------------------------------------------------

    public function testParseIfMatchReturnsNullForNull(): void
    {
        self::assertNull(OptimisticLock::parseIfMatch(null));
    }

    public function testParseIfMatchReturnsNullForEmptyString(): void
    {
        self::assertNull(OptimisticLock::parseIfMatch(''));
    }

    public function testParseIfMatchParsesQuotedVPrefix(): void
    {
        self::assertSame(5, OptimisticLock::parseIfMatch('"v5"'));
    }

    public function testParseIfMatchParsesQuotedDigitOnly(): void
    {
        self::assertSame(5, OptimisticLock::parseIfMatch('"5"'));
    }

    public function testParseIfMatchParsesUnquotedVPrefix(): void
    {
        self::assertSame(5, OptimisticLock::parseIfMatch('v5'));
    }

    public function testParseIfMatchParsesUnquotedDigitOnly(): void
    {
        self::assertSame(5, OptimisticLock::parseIfMatch('5'));
    }

    public function testParseIfMatchReturnsNullForVersionZero(): void
    {
        self::assertNull(OptimisticLock::parseIfMatch('"v0"'));
    }

    public function testParseIfMatchReturnsNullForNonNumeric(): void
    {
        self::assertNull(OptimisticLock::parseIfMatch('"abc"'));
    }

    public function testParseIfMatchReturnsNullForNegativeVersion(): void
    {
        // After stripping quotes: "v-1" → ltrim('v') leaves "-1"; ctype_digit('-1') is false
        self::assertNull(OptimisticLock::parseIfMatch('"v-1"'));
    }

    // -----------------------------------------------------------------
    // etagFor
    // -----------------------------------------------------------------

    public function testEtagForVersion1(): void
    {
        self::assertSame('"v1"', OptimisticLock::etagFor(1));
    }

    public function testEtagForVersion42(): void
    {
        self::assertSame('"v42"', OptimisticLock::etagFor(42));
    }

    // -----------------------------------------------------------------
    // requireVersion — throws 428 when If-Match is absent
    // -----------------------------------------------------------------

    public function testRequireVersionThrows428WhenAbsent(): void
    {
        unset($_SERVER['HTTP_IF_MATCH']);
        $this->expectException(HttpTermination::class);
        OptimisticLock::requireVersion();
    }

    public function testRequireVersionThrows428WhenEmpty(): void
    {
        $_SERVER['HTTP_IF_MATCH'] = '';
        try {
            OptimisticLock::requireVersion();
            self::fail('Expected HttpTermination was not thrown.');
        } catch (HttpTermination $e) {
            self::assertSame(428, $e->response()->statusCode());
        } finally {
            unset($_SERVER['HTTP_IF_MATCH']);
        }
    }

    public function testRequireVersionReturnsVersionWhenValid(): void
    {
        $_SERVER['HTTP_IF_MATCH'] = '"v7"';
        try {
            $version = OptimisticLock::requireVersion();
            self::assertSame(7, $version);
        } finally {
            unset($_SERVER['HTTP_IF_MATCH']);
        }
    }

    // -----------------------------------------------------------------
    // conflict — throws 412
    // -----------------------------------------------------------------

    public function testConflictThrows412(): void
    {
        try {
            OptimisticLock::conflict();
            self::fail('Expected HttpTermination was not thrown.');
        } catch (HttpTermination $e) {
            self::assertSame(412, $e->response()->statusCode());
            self::assertStringContainsString('PRECONDITION-FAILED', $e->response()->body());
        }
    }
}
