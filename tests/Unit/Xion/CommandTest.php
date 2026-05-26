<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Command;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Named test fixtures declared at file scope so Phan can resolve their types.
// ---------------------------------------------------------------------------

/**
 * A concrete Command that records the options it received in `handle()`.
 * Tests inject options via the overridden `parseArguments()` method.
 */
final class RecordingCommand extends Command
{
    /** @var array<string, string|false> */
    public array $receivedOptions = [];

    /**
     * @param int                                          $exitCode      Returned by handle().
     * @param array<string, string|false>                  $parsedOptions Injected via parseArguments().
     * @param array<string, 'none'|'optional'|'required'> $optionDefs    Reported via optionDefinitions().
     * @param string                                       $help          Returned by helpText().
     */
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly array $parsedOptions = [],
        private readonly array $optionDefs = [],
        private readonly string $help = '',
    ) {
    }

    /** @return array<string, 'none'|'optional'|'required'> */
    protected function optionDefinitions(): array
    {
        return $this->optionDefs;
    }

    protected function helpText(): string
    {
        return $this->help;
    }

    /** @param array<string, string|false> $options */
    protected function handle(array $options): int
    {
        $this->receivedOptions = $options;
        return $this->exitCode;
    }

    /**
     * @param  list<string>                $longOpts
     * @return array<string, string|false>
     */
    protected function parseArguments(array $longOpts): array
    {
        return $this->parsedOptions;
    }
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

/**
 * Unit tests for the abstract {@see Command} base class.
 *
 * All tests use {@see RecordingCommand} which overrides parseArguments() so
 * no real getopt() / process argv is involved.
 */
final class CommandTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // execute() — exit code pass-through
    // ------------------------------------------------------------------ //

    public function testExecuteReturnsZeroOnSuccess(): void
    {
        $cmd = new RecordingCommand(exitCode: 0);
        self::assertSame(0, $cmd->execute());
    }

    public function testExecuteReturnsNonZeroOnFailure(): void
    {
        $cmd = new RecordingCommand(exitCode: 2);
        self::assertSame(2, $cmd->execute());
    }

    // ------------------------------------------------------------------ //
    // --help flag
    // ------------------------------------------------------------------ //

    public function testHelpFlagReturnsZeroWithoutCallingHandle(): void
    {
        // handle() would return 42; if --help is intercepted first, 0 is returned.
        $cmd  = new RecordingCommand(exitCode: 42, parsedOptions: ['help' => false], help: 'usage…');
        $code = $cmd->execute();

        self::assertSame(0, $code);
        // handle() was never called, so receivedOptions is still empty.
        self::assertSame([], $cmd->receivedOptions);
    }

    public function testHelpFlagOutputsHelpText(): void
    {
        $cmd = new RecordingCommand(parsedOptions: ['help' => false], help: 'Expected help output.');

        ob_start();
        $cmd->execute();
        $output = (string)ob_get_clean();

        self::assertStringContainsString('Expected help output.', $output);
    }

    public function testNoHelpFlagCallsHandle(): void
    {
        $cmd = new RecordingCommand(exitCode: 7, parsedOptions: []);
        self::assertSame(7, $cmd->execute());
    }

    public function testHelpIsAlwaysRegisteredEvenWithNoOptionDefinitions(): void
    {
        $cmd = new RecordingCommand(exitCode: 99, parsedOptions: ['help' => false], help: 'minimal help');

        ob_start();
        $code = $cmd->execute();
        $out  = (string)ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('minimal help', $out);
    }

    // ------------------------------------------------------------------ //
    // Option parsing — options are forwarded to handle()
    // ------------------------------------------------------------------ //

    public function testNoneFlagIsForwardedToHandle(): void
    {
        $cmd = new RecordingCommand(
            optionDefs:    ['yes' => 'none'],
            parsedOptions: ['yes' => false],
        );
        $cmd->execute();

        self::assertArrayHasKey('yes', $cmd->receivedOptions);
        self::assertFalse($cmd->receivedOptions['yes']);
    }

    public function testOptionalValueIsForwardedToHandle(): void
    {
        $cmd = new RecordingCommand(
            optionDefs:    ['env' => 'optional'],
            parsedOptions: ['env' => '.env.test'],
        );
        $cmd->execute();

        self::assertSame('.env.test', $cmd->receivedOptions['env'] ?? null);
    }

    public function testRequiredValueIsForwardedToHandle(): void
    {
        $cmd = new RecordingCommand(
            optionDefs:    ['dsn' => 'required'],
            parsedOptions: ['dsn' => 'sqlite:/tmp/test.db'],
        );
        $cmd->execute();

        self::assertSame('sqlite:/tmp/test.db', $cmd->receivedOptions['dsn'] ?? null);
    }

    public function testAbsentOptionIsNotInReceivedOptions(): void
    {
        $cmd = new RecordingCommand(optionDefs: ['yes' => 'none'], parsedOptions: []);
        $cmd->execute();

        self::assertArrayNotHasKey('yes', $cmd->receivedOptions);
    }

    public function testMultipleOptionsAreAllForwardedToHandle(): void
    {
        $cmd = new RecordingCommand(
            optionDefs:    ['yes' => 'none', 'env' => 'optional'],
            parsedOptions: ['yes' => false, 'env' => 'staging.env'],
        );
        $cmd->execute();

        self::assertArrayHasKey('yes', $cmd->receivedOptions);
        self::assertSame('staging.env', $cmd->receivedOptions['env'] ?? null);
    }

    // ------------------------------------------------------------------ //
    // Output helpers — tested through anonymous subclasses
    // ------------------------------------------------------------------ //

    public function testLineOutputsMessageWithNewline(): void
    {
        $cmd = new class extends Command {
            protected function helpText(): string { return ''; }
            /** @param array<string, string|false> $options */
            protected function handle(array $options): int
            {
                $this->line('hello world');
                return 0;
            }
        };

        ob_start();
        $cmd->execute();
        self::assertSame('hello world' . PHP_EOL, (string)ob_get_clean());
    }

    public function testLineWithNoArgumentOutputsBlankLine(): void
    {
        $cmd = new class extends Command {
            protected function helpText(): string { return ''; }
            /** @param array<string, string|false> $options */
            protected function handle(array $options): int
            {
                $this->line();
                return 0;
            }
        };

        ob_start();
        $cmd->execute();
        self::assertSame(PHP_EOL, (string)ob_get_clean());
    }

    public function testErrorReturnsCorrectExitCode(): void
    {
        $cmd = new class extends Command {
            protected function helpText(): string { return ''; }
            /** @param array<string, string|false> $options */
            protected function handle(array $options): int
            {
                $this->error('something went wrong');
                return 1;
            }
        };

        // STDERR is hard to capture in PHPUnit; verify exit code and no exception.
        self::assertSame(1, $cmd->execute());
    }

    public function testWriteOutputsWithoutTrailingNewline(): void
    {
        $cmd = new class extends Command {
            protected function helpText(): string { return ''; }
            /** @param array<string, string|false> $options */
            protected function handle(array $options): int
            {
                $this->write('no-newline');
                return 0;
            }
        };

        ob_start();
        $cmd->execute();
        self::assertSame('no-newline', (string)ob_get_clean());
    }

    public function testLineAndWriteProduceDistinctOutput(): void
    {
        $cmd = new class extends Command {
            protected function helpText(): string { return ''; }
            /** @param array<string, string|false> $options */
            protected function handle(array $options): int
            {
                $this->write('prefix:');
                $this->line('suffix');
                return 0;
            }
        };

        ob_start();
        $cmd->execute();
        self::assertSame('prefix:suffix' . PHP_EOL, (string)ob_get_clean());
    }
}
