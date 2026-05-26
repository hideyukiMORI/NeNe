<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Nene\Xion\LogFormatterFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests LogFormatterFactory — the formatter selection logic extracted from Log.
 *
 * LogFormatterFactory::create(string $format) has no side-effects and no
 * dependencies on file-path constants, so every case runs in the same process.
 *
 * FT19 / structured-logs.
 */
class LogFormatterTest extends TestCase
{
    public function testTextFormatReturnsLineFormatter(): void
    {
        $formatter = LogFormatterFactory::create('text');

        $this->assertInstanceOf(
            LineFormatter::class,
            $formatter,
            'format=text must produce a LineFormatter'
        );
    }

    public function testJsonFormatReturnsJsonFormatter(): void
    {
        $formatter = LogFormatterFactory::create('json');

        $this->assertInstanceOf(
            JsonFormatter::class,
            $formatter,
            'format=json must produce a JsonFormatter'
        );
    }

    public function testDefaultFormatIsText(): void
    {
        // No argument → text
        $formatter = LogFormatterFactory::create();

        $this->assertInstanceOf(
            LineFormatter::class,
            $formatter,
            'Default (no argument) must produce a LineFormatter'
        );
    }

    public function testUnknownFormatFallsBackToText(): void
    {
        $formatter = LogFormatterFactory::create('logstash');

        $this->assertInstanceOf(
            LineFormatter::class,
            $formatter,
            'Unknown format strings must fall back to LineFormatter'
        );
    }

    public function testFromConstantWithNoConstantDefinedReturnsLineFormatter(): void
    {
        // In the unit-test bootstrap, LOG_FORMAT is not defined.
        // fromConstant() must fall back to text gracefully.
        if (defined('LOG_FORMAT')) {
            $this->markTestSkipped('LOG_FORMAT is already defined in this process; cannot test undefined path.');
        }

        $formatter = LogFormatterFactory::fromConstant();

        $this->assertInstanceOf(
            LineFormatter::class,
            $formatter,
            'fromConstant() when LOG_FORMAT is undefined must default to LineFormatter'
        );
    }

    public function testJsonFormatterOutputIsValidJson(): void
    {
        // Smoke-test: a record processed by JsonFormatter must decode cleanly.
        $formatter = LogFormatterFactory::create('json');
        $record = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'structured log test',
            context: ['key' => 'value'],
            extra: [],
        );

        $line = $formatter->format($record);

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, 'JsonFormatter output must be valid JSON');
        $this->assertSame('structured log test', $decoded['message']);
        $this->assertSame('INFO', $decoded['level_name']);
    }

    public function testLineFormatterOutputIsNotJson(): void
    {
        // Smoke-test: a record processed by LineFormatter should NOT be pure JSON.
        $formatter = LogFormatterFactory::create('text');
        $record = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'plain text log test',
            context: [],
            extra: [],
        );

        $line = $formatter->format($record);

        // LineFormatter includes brackets/braces but is not a standalone JSON object
        $decoded = json_decode(trim($line), true);
        $this->assertNull(
            $decoded,
            'LineFormatter output must not be a valid top-level JSON object'
        );
    }
}
