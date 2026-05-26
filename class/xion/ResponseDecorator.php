<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Cross-cutting response decoration that every PHP-generated response
 * picks up regardless of the path it took (REST success, 4xx/5xx,
 * `HttpTermination`, `DomainException`, uncaught `\Throwable`, HTML
 * `Smarty::display()`).
 *
 * Closes the FT7 F-6 / FT8 F-4 structural finding: the dispatcher's
 * early-exit paths skip `ControllerBase::run()`'s tail, so any decoration
 * added there silently misses error responses. `ResponseDecorator` lives
 * at the lower boundary instead — `HttpEmitter::emit()` calls
 * {@see decorate()} on every response, and `View::execute()` calls
 * {@see sendHeaders()} before Smarty echoes (the one path that bypasses
 * `HttpEmitter`).
 *
 * The default header set is intentionally tiny: `X-Content-Type-Options:
 * nosniff` is always on because the cost is zero and the protection is
 * universal. Other headers (CSP / HSTS / X-Frame-Options / Referrer-Policy
 * / Permissions-Policy) are opt-in via env so operators decide policy
 * per deployment. See ADR-0007.
 */
final class ResponseDecorator
{
    /** @var array<string,string>|null */
    private static ?array $cached = null;

    private const ALWAYS_ON = [
        'X-Content-Type-Options' => 'nosniff',
    ];

    private const ENV_MAP = [
        'NENE_SECURITY_CSP'                => 'Content-Security-Policy',
        'NENE_SECURITY_FRAME_OPTIONS'      => 'X-Frame-Options',
        'NENE_SECURITY_REFERRER_POLICY'    => 'Referrer-Policy',
        'NENE_SECURITY_HSTS'               => 'Strict-Transport-Security',
        'NENE_SECURITY_PERMISSIONS_POLICY' => 'Permissions-Policy',
    ];

    /**
     * Headers this decorator will attempt to add. Existing response
     * headers always win (controllers can override per-response).
     *
     * Static env-driven headers are cached for the process; the
     * per-request request-id header is added fresh on every call so
     * that `RequestId::current()` resolves once per request and
     * propagates here automatically (FT15 / ADR-0007 second use case).
     *
     * @return array<string,string>
     */
    public static function headers(): array
    {
        $headers = self::staticHeaders();

        // Per-request request-id (FT15 / ADR-0007)
        $requestIdHeader = RequestId::headerName();
        if ($requestIdHeader !== '') {
            $headers[$requestIdHeader] = RequestId::current();
        }

        // Per-request Server-Timing (FT20 / ADR-0007 future concern)
        if (ServerTiming::isEnabled()) {
            $headers['Server-Timing'] = ServerTiming::headerValue();
        }

        return $headers;
    }

    /**
     * Process-cached headers driven by `NENE_SECURITY_*` env vars plus
     * the always-on defaults. Does **not** include per-request headers
     * (request-id, Server-Timing) — those are added by {@see headers()}.
     *
     * @return array<string,string>
     */
    private static function staticHeaders(): array
    {
        if (self::$cached === null) {
            $headers = self::ALWAYS_ON;
            foreach (self::ENV_MAP as $envName => $headerName) {
                $value = (string)(getenv($envName) ?: '');
                if ($value !== '') {
                    $headers[$headerName] = $value;
                }
            }
            self::$cached = $headers;
        }
        return self::$cached;
    }

    /**
     * Forget the cached headers so the next call rebuilds from the
     * current environment. Used by tests that flip `putenv()` between
     * assertions.
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /**
     * Return a new {@see HttpResponse} carrying every decorator header
     * that the original did not already set. The caller's headers win.
     */
    public static function decorate(HttpResponse $response): HttpResponse
    {
        $existing = $response->headers();
        $existingLower = array_change_key_case($existing, CASE_LOWER);
        $combined = $existing;
        foreach (self::headers() as $name => $value) {
            if (!isset($existingLower[strtolower($name)])) {
                $combined[$name] = $value;
            }
        }
        return new HttpResponse($response->statusCode(), $combined, $response->body());
    }

    /**
     * Emit the decorator's headers via PHP's `header()` directly. Used
     * by paths that do not flow through {@see HttpEmitter} — currently
     * only `View::execute()`, which lets Smarty stream the body to
     * stdout. Skips any header PHP has already emitted (`headers_list`).
     */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $existing = array_map('strtolower', array_map(
            static fn (string $h): string => (string)strstr($h, ':', true),
            headers_list()
        ));
        foreach (self::headers() as $name => $value) {
            if (in_array(strtolower($name), $existing, true)) {
                continue;
            }
            header($name . ': ' . $value);
        }
    }
}
