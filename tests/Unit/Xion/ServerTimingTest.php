<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ServerTiming;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nene\Xion\ServerTiming (FT20).
 *
 * ServerTiming is a pure-static class with no external dependencies.
 * Every test calls reset() in setUp/tearDown to isolate state.
 */
class ServerTimingTest extends TestCase
{
    protected function setUp(): void
    {
        ServerTiming::reset();
        putenv('NENE_SERVER_TIMING_ENABLED');      // unset
    }

    protected function tearDown(): void
    {
        ServerTiming::reset();
        putenv('NENE_SERVER_TIMING_ENABLED');      // unset
    }

    // ------------------------------------------------------------------ //
    // elapsed()
    // ------------------------------------------------------------------ //

    public function testElapsedReturnsZeroWhenStartNotCalled(): void
    {
        $this->assertSame(0.0, ServerTiming::elapsed());
    }

    public function testElapsedReturnsPositiveAfterStart(): void
    {
        ServerTiming::start();
        usleep(1000); // 1 ms
        $elapsed = ServerTiming::elapsed();

        $this->assertGreaterThan(0.0, $elapsed, 'elapsed must be positive after start()');
    }

    public function testStartResetsTimer(): void
    {
        ServerTiming::start();
        usleep(5000);
        $first = ServerTiming::elapsed();

        ServerTiming::start();
        $second = ServerTiming::elapsed();

        $this->assertLessThan($first, $second, 'second start() must reset the clock');
    }

    public function testResetMakesElapsedZero(): void
    {
        ServerTiming::start();
        usleep(1000);
        ServerTiming::reset();

        $this->assertSame(0.0, ServerTiming::elapsed());
    }

    // ------------------------------------------------------------------ //
    // headerValue()
    // ------------------------------------------------------------------ //

    public function testHeaderValueFormatWhenNotStarted(): void
    {
        $value = ServerTiming::headerValue();

        $this->assertSame('app;dur=0.0', $value, 'no start() → app;dur=0.0');
    }

    public function testHeaderValueHasCorrectPrefix(): void
    {
        ServerTiming::start();
        $value = ServerTiming::headerValue();

        $this->assertStringStartsWith('app;dur=', $value);
    }

    public function testHeaderValueDurIsNumeric(): void
    {
        ServerTiming::start();
        usleep(1000);
        $value = ServerTiming::headerValue();

        // Format: app;dur=<float>
        $this->assertMatchesRegularExpression('/^app;dur=\d+\.\d$/', $value);
    }

    // ------------------------------------------------------------------ //
    // isEnabled()
    // ------------------------------------------------------------------ //

    public function testDisabledByDefault(): void
    {
        // NENE_SERVER_TIMING_ENABLED is unset in setUp
        $this->assertFalse(ServerTiming::isEnabled());
    }

    public function testEnabledWhenSetToOne(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=1');
        $this->assertTrue(ServerTiming::isEnabled());
    }

    public function testEnabledWhenSetToTrue(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=true');
        $this->assertTrue(ServerTiming::isEnabled());
    }

    public function testEnabledWhenSetToOn(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=on');
        $this->assertTrue(ServerTiming::isEnabled());
    }

    public function testDisabledWhenSetToZero(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=0');
        $this->assertFalse(ServerTiming::isEnabled());
    }

    public function testDisabledWhenSetToFalse(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=false');
        $this->assertFalse(ServerTiming::isEnabled());
    }

    public function testDisabledWhenSetToEmptyString(): void
    {
        putenv('NENE_SERVER_TIMING_ENABLED=');
        $this->assertFalse(ServerTiming::isEnabled());
    }
}
