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
 * Per-request Server-Timing header support (FT20 / ADR-0007 future concern).
 *
 * Records the PHP start time via {@see start()} in the front-controller
 * bootstrap, then exposes the elapsed time in milliseconds when the
 * response decorator runs — giving browsers and aggregators a cheap,
 * zero-dependency performance signal with no application code changes.
 *
 * Header format (W3C Server-Timing spec):
 *   Server-Timing: app;dur=12.3
 *
 * - Metric name `app` identifies the PHP application layer (from
 *   {@see start()} to the point the header is serialised).
 * - `dur` is elapsed time in milliseconds, one decimal place.
 * - Disabled by default. Set `NENE_SERVER_TIMING_ENABLED=1` to opt in.
 *
 * Follows the same resolution shape as {@see RequestId}:
 *  - Resolved per request (not cached across requests).
 *  - {@see ResponseDecorator::headers()} adds the header when enabled.
 *  - {@see reset()} clears state between test assertions.
 *
 * See `docs/development/observability.md` and ADR-0007.
 */
final class ServerTiming
{
    private static ?int $startNs = null;

    /**
     * Record the request start time.
     *
     * Call once at the very top of the front controller bootstrap
     * (immediately after `require_once 'vendor/autoload.php'`) so the
     * elapsed time covers as much of the PHP execution as possible.
     *
     * Calling `start()` more than once resets the clock (safe for tests
     * that invoke it multiple times via `reset()`).
     */
    public static function start(): void
    {
        self::$startNs = hrtime(true);
    }

    /**
     * Elapsed time in milliseconds since {@see start()} was called.
     *
     * Returns `0.0` when `start()` was never called — this keeps the
     * header value well-formed even if the bootstrap order changes.
     */
    public static function elapsed(): float
    {
        if (self::$startNs === null) {
            return 0.0;
        }

        return (hrtime(true) - self::$startNs) / 1_000_000;
    }

    /**
     * The `Server-Timing` header value for this request.
     *
     * Format: `app;dur=<ms>` where `<ms>` is elapsed time to one decimal
     * place. The `app` metric name identifies the PHP application layer.
     */
    public static function headerValue(): string
    {
        return sprintf('app;dur=%.1f', self::elapsed());
    }

    /**
     * Whether the `Server-Timing` header should be emitted.
     *
     * Controlled by `NENE_SERVER_TIMING_ENABLED` (default `0` / off).
     * Enable with `NENE_SERVER_TIMING_ENABLED=1` in production or dev.
     *
     * Off by default because:
     *  - The header exposes internal timing, which can assist attackers
     *    doing side-channel analysis on auth or rate-limit paths.
     *  - Operators must make an explicit decision to share timing data
     *    with browsers / CDNs.
     */
    public static function isEnabled(): bool
    {
        $raw = getenv('NENE_SERVER_TIMING_ENABLED');
        $value = strtolower($raw === false ? '0' : (string)$raw);
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Reset the recorded start time and re-read the enabled flag.
     *
     * Used by tests to isolate state between assertions.
     */
    public static function reset(): void
    {
        self::$startNs = null;
    }
}
