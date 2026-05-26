<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Abstract base class for NeNe CLI commands.
 *
 * Concrete commands extend this class and implement:
 * - {@see optionDefinitions()} — declare accepted --options
 * - {@see helpText()}          — usage string shown for --help
 * - {@see handle()}            — business logic; returns an exit code
 *
 * The `--help` flag is registered automatically and handled before
 * {@see handle()} is called, so subclasses never need to check for it.
 *
 * Entry point in the thin CLI script:
 *
 * ```php
 * exit((new MyCommand())->execute());
 * ```
 *
 * Helper methods available in subclasses:
 * - {@see line()}    — write a line to STDOUT
 * - {@see error()}   — write a line to STDERR
 * - {@see confirm()} — interactive Y/N prompt; returns bool
 */
abstract class Command
{
    /**
     * Declare accepted long options (without leading `--`).
     *
     * Return an associative array where:
     * - key   = option name
     * - value = 'none'     (flag: --flag, no value)
     *         | 'optional' (--opt or --opt=value)
     *         | 'required' (--opt=value, fails silently if omitted from getopt)
     *
     * The `help` option is registered automatically; do not declare it here.
     *
     * @return array<string, 'none'|'optional'|'required'>
     */
    protected function optionDefinitions(): array
    {
        return [];
    }

    /**
     * Return the usage string displayed when `--help` is passed.
     *
     * Include the trailing newline if you want a blank line after the text.
     */
    abstract protected function helpText(): string;

    /**
     * Run the command.
     *
     * @param  array<string, string|false> $options  Parsed options from getopt.
     *                                               Flag options present on the
     *                                               command line have value `false`.
     * @return int  Exit code (0 = success, non-zero = failure).
     */
    abstract protected function handle(array $options): int;

    /**
     * Parse options and dispatch.
     *
     * Called by the thin CLI script: `exit($command->execute())`.
     */
    public function execute(): int
    {
        $defs     = array_merge($this->optionDefinitions(), ['help' => 'none']);
        $longOpts = [];
        foreach ($defs as $name => $kind) {
            $longOpts[] = $name . match ($kind) {
                'required' => ':',
                'optional' => '::',
                default    => '',
            };
        }

        /** @var array<string, string|false> $options */
        $options = $this->parseArguments($longOpts);

        if (isset($options['help'])) {
            $this->line($this->helpText());
            return 0;
        }

        return $this->handle($options);
    }

    /**
     * Write a message followed by a newline to STDOUT.
     *
     * Pass an empty string (or no argument) for a blank line.
     */
    protected function line(string $msg = ''): void
    {
        echo $msg . PHP_EOL;
    }

    /**
     * Write an error message followed by a newline to STDERR.
     */
    protected function error(string $msg): void
    {
        fwrite(STDERR, $msg . PHP_EOL);
    }

    /**
     * Write to STDOUT without a trailing newline — for cases where the
     * caller controls line breaks (e.g. bulk SQL output that may be
     * redirected to a file).
     */
    protected function write(string $msg): void
    {
        echo $msg;
    }

    /**
     * Parse long options from the process argv.
     *
     * Separated into its own method so test subclasses can override it
     * to inject pre-parsed option arrays without running getopt().
     *
     * @param  list<string>                $longOpts  getopt() long-option specs.
     * @return array<string, string|false>
     */
    protected function parseArguments(array $longOpts): array
    {
        return getopt('', $longOpts) ?: [];
    }

    /**
     * Display an interactive Y/N prompt and return true if the user
     * answers "y" or "yes" (case-insensitive).
     *
     * Skip in non-interactive contexts by passing `--yes` before
     * calling this method.
     */
    protected function confirm(string $question = 'Do you want to continue? (Y/N)'): bool
    {
        $this->line($question);
        $answer = trim((string)fgets(STDIN));
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }
}
