<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Per-request identifier used for end-to-end correlation across
 * response headers, access logs, and error logs.
 *
 * The identifier is resolved once per request and cached for the
 * duration. Resolution order:
 *
 *   1. If `NENE_REQUEST_ID_TRUST_INBOUND` is truthy (default), look at
 *      the header named in `NENE_REQUEST_ID_HEADER` (default
 *      `X-Request-ID`). If the value passes {@see isValid()}, honor it.
 *   2. Otherwise generate a 32-character hex from `random_bytes(16)`.
 *
 * Validation is intentionally strict — a request-id that travels into
 * log files must not carry control chars or unbounded length, because
 * those are how attackers poison aggregated logs. Anything that fails
 * validation is silently replaced by a generated value.
 *
 * Used by:
 *
 *   - {@see ResponseDecorator}     — emits the value as `X-Request-ID`.
 *   - {@see Log} (Monolog processor) — injects `request_id` into every
 *     log record's `extra` context.
 *
 * See ADR-0007 for the underlying cross-cutting decoration boundary.
 */
final class RequestId
{
    private const DEFAULT_HEADER = 'X-Request-ID';
    private const MAX_LENGTH = 128;
    private const VALID_PATTERN = '/^[A-Za-z0-9_\-\.:]+$/';

    private static ?string $current = null;

    /**
     * Return the request-id for the current request, resolving it on the
     * first call and caching it for the rest of the dispatch.
     */
    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = self::resolve();
        }
        return self::$current;
    }

    /**
     * The configured response-header name (default `X-Request-ID`).
     * An empty string means "do not emit a request-id header" — the
     * decorator skips emission, but `Log` still tags records with the
     * resolved id for internal correlation.
     */
    public static function headerName(): string
    {
        $value = getenv('NENE_REQUEST_ID_HEADER');
        return $value === false ? self::DEFAULT_HEADER : (string)$value;
    }

    /**
     * Forget the cached value. Used by tests between assertions.
     */
    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Whether the configured trust setting honors an inbound header.
     */
    public static function trustsInbound(): bool
    {
        $raw = getenv('NENE_REQUEST_ID_TRUST_INBOUND');
        $value = strtolower($raw === false ? '1' : (string)$raw);
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Validate an inbound request-id. Strict by design (charset +
     * length cap) so values that survive validation are safe to inject
     * into log files and response headers without further escaping.
     */
    public static function isValid(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (strlen($value) > self::MAX_LENGTH) {
            return false;
        }
        return preg_match(self::VALID_PATTERN, $value) === 1;
    }

    private static function resolve(): string
    {
        if (self::trustsInbound()) {
            $headerName = self::headerName();
            if ($headerName !== '') {
                $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
                $inbound = $_SERVER[$serverKey] ?? null;
                if (is_string($inbound) && self::isValid($inbound)) {
                    return $inbound;
                }
            }
        }
        return self::generate();
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
