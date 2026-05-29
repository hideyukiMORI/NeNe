<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * CORS (Cross-Origin Resource Sharing) header utility.
 *
 * Handles both the actual request headers and the OPTIONS preflight response.
 *
 * Usage in ControllerBase::preAction():
 *
 *   protected function preAction(): void
 *   {
 *       Cors::sendHeaders(
 *           allowedOrigins: ['https://app.example.com', 'https://admin.example.com'],
 *           allowedMethods: ['GET', 'POST', 'PUT', 'DELETE'],
 *           allowedHeaders: ['Content-Type', 'X-CSRF-Token'],
 *           credentials:    true,
 *       );
 *       Cors::handlePreflight();  // exits on OPTIONS, no-op otherwise
 *   }
 */
final class Cors
{
    /**
     * Send CORS response headers.
     *
     * Origin matching:
     * - Pass `['*']` to allow any origin (incompatible with credentials: true).
     * - Pass a list of origins; the actual `Origin` request header is matched
     *   case-insensitively. If matched, the specific origin is reflected back
     *   (required for cookies/credentials). If not matched, no CORS headers
     *   are sent.
     *
     * @param string[]  $allowedOrigins List of allowed origins, or ['*'] for wildcard.
     * @param string[]  $allowedMethods HTTP methods (e.g. ['GET', 'POST', 'DELETE']).
     * @param string[]  $allowedHeaders Request headers the client may send.
     * @param bool      $credentials    Whether cookies/auth headers are allowed.
     * @param int       $maxAge         Preflight cache in seconds (default 86400 = 24h).
     * @param string[]  $exposeHeaders  Response headers the browser may read.
     * @param string|null $origin       Injectable for tests (defaults to Origin header).
     */
    public static function sendHeaders(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type'],
        bool $credentials = false,
        int $maxAge = 86400,
        array $exposeHeaders = [],
        ?string $origin = null,
    ): void {
        $requestOrigin = $origin ?? ($_SERVER['HTTP_ORIGIN'] ?? null);

        if ($requestOrigin === null) {
            return;
        }

        // Wildcard
        if (in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } else {
            // Reflect only if the origin is in the allow-list
            $matched = false;
            foreach ($allowedOrigins as $allowed) {
                if (strcasecmp($requestOrigin, $allowed) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return;
            }
            header('Access-Control-Allow-Origin: ' . $requestOrigin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
        header('Access-Control-Max-Age: ' . $maxAge);

        if ($credentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        if (!empty($exposeHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $exposeHeaders));
        }
    }

    /**
     * Handle a CORS preflight OPTIONS request.
     *
     * If the current request method is OPTIONS, emits HTTP 204 and exits.
     * For all other methods this is a no-op.
     *
     * Call after sendHeaders() so the CORS headers are already queued.
     *
     * @param string|null $method Injectable for tests (defaults to REQUEST_METHOD).
     */
    public static function handlePreflight(?string $method = null): void
    {
        $currentMethod = $method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (strtoupper($currentMethod) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Check whether a given origin is in the allow-list.
     *
     * Comparison is case-insensitive.
     *
     * @param string   $origin         Origin to test (e.g. 'https://app.example.com').
     * @param string[] $allowedOrigins Allow-list.
     * @return bool
     */
    public static function isAllowed(string $origin, array $allowedOrigins): bool
    {
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }
        foreach ($allowedOrigins as $allowed) {
            if (strcasecmp($origin, $allowed) === 0) {
                return true;
            }
        }
        return false;
    }
}
