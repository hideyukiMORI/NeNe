<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * RFC 8594 API deprecation signal helpers.
 *
 * Call sendHeaders() in preAction() or at the start of any deprecated
 * REST method to notify clients that the API version is being retired.
 *
 * Clients, API gateways, and SDKs that respect RFC 8594 will surface
 * the deprecation to developers automatically.
 *
 * ## Usage in a deprecated controller
 *
 * ```php
 * // v1/NoteController.php
 * protected function preAction(): void
 * {
 *     ApiDeprecation::sendHeaders(
 *         sunsetDate: '2027-01-01',
 *         successor:  '/v2/notes',
 *     );
 * }
 * ```
 */
final class ApiDeprecation
{
    /**
     * Emit RFC 8594 deprecation headers for the current response.
     *
     * @param string      $sunsetDate  ISO 8601 date (YYYY-MM-DD) or RFC 7231 datetime when the API retires.
     * @param string|null $successor   URI of the replacement endpoint (for the Link header).
     *
     * @return void
     */
    public static function sendHeaders(string $sunsetDate, ?string $successor = null): void
    {
        header('Deprecation: true');
        header('Sunset: ' . self::toHttpDate($sunsetDate));

        if ($successor !== null) {
            header('Link: <' . $successor . '>; rel="successor-version"');
        }
    }

    /**
     * Format a date string as an HTTP-date (RFC 7231).
     * Accepts YYYY-MM-DD (converted to 23:59:59 UTC of that day) or
     * already-formatted RFC 7231 strings (passed through unchanged).
     */
    private static function toHttpDate(string $date): string
    {
        // If it already looks like an RFC 7231 date, pass through
        if (preg_match('/^\w+, \d+ \w+ \d{4}/', $date)) {
            return $date;
        }
        // YYYY-MM-DD → RFC 7231
        $ts = strtotime($date . ' 23:59:59 UTC');
        if ($ts === false) {
            return $date; // fallback: return as-is
        }
        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }
}
