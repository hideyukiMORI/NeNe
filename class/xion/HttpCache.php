<?php
declare(strict_types=1);
namespace Nene\Xion;

/**
 * HTTP cache header utilities for REST endpoints.
 *
 * Handles Cache-Control directives, Last-Modified, and conditional GET
 * (If-Modified-Since → 304 Not Modified).
 *
 * Usage in a controller action:
 *
 *   public function indexGetRest(): array
 *   {
 *       $lastModified = '2026-01-15 10:30:00';
 *       if (HttpCache::isNotModified($lastModified)) {
 *           HttpCache::send304();
 *       }
 *       HttpCache::sendCacheControl(maxAge: 60);
 *       HttpCache::sendLastModified($lastModified);
 *       return $this->API_RESPONSE->success(['items' => $this->mapper->findALL()]);
 *   }
 */
final class HttpCache
{
    /**
     * Emit a Cache-Control header.
     *
     * @param int  $maxAge    Seconds the response may be cached.
     * @param bool $private   Use `private` (per-user cache) instead of `public` (shared).
     * @param bool $immutable Add `immutable` directive (content will never change).
     * @param bool $noStore   Set `no-store` (disables all caching; overrides other args).
     */
    public static function sendCacheControl(
        int $maxAge = 0,
        bool $private = false,
        bool $immutable = false,
        bool $noStore = false,
    ): void {
        if ($noStore) {
            header('Cache-Control: no-store');
            return;
        }
        $parts = [];
        $parts[] = $private ? 'private' : 'public';
        $parts[] = 'max-age=' . max(0, $maxAge);
        if ($immutable) {
            $parts[] = 'immutable';
        }
        header('Cache-Control: ' . implode(', ', $parts));
    }

    /**
     * Emit a Last-Modified header.
     *
     * Accepts a MySQL DATETIME string (`YYYY-MM-DD HH:MM:SS`) or a
     * Unix timestamp. Formats as RFC 7231 (e.g. `Tue, 15 Jan 2026 10:30:00 GMT`).
     *
     * @param string|int $lastModified MySQL DATETIME or Unix timestamp.
     */
    public static function sendLastModified(string|int $lastModified): void
    {
        $ts = is_int($lastModified) ? $lastModified : strtotime($lastModified);
        if ($ts === false) {
            return;
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $ts) . ' GMT');
    }

    /**
     * Check if the client's cached copy is still fresh.
     *
     * Parses the `If-Modified-Since` request header and compares it with
     * the given $lastModified value. Returns true if the resource has NOT
     * been modified (i.e., the client cache is still valid).
     *
     * @param string|int $lastModified The actual last-modified time of the resource.
     * @param string|null $ifModifiedSince Injectable for tests (defaults to request header).
     */
    public static function isNotModified(
        string|int $lastModified,
        ?string $ifModifiedSince = null,
    ): bool {
        $header = $ifModifiedSince ?? ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null);
        if ($header === null || $header === '') {
            return false;
        }

        $clientTs   = strtotime($header);
        $resourceTs = is_int($lastModified) ? $lastModified : strtotime($lastModified);

        if ($clientTs === false || $resourceTs === false) {
            return false;
        }

        return $clientTs >= $resourceTs;
    }

    /**
     * Send a 304 Not Modified response and terminate.
     *
     * Sends the status code and exits. The response body is empty.
     */
    public static function send304(): never
    {
        http_response_code(304);
        exit;
    }

    /**
     * Send Pragma: no-cache + Cache-Control: no-cache, no-store for sensitive pages.
     *
     * Useful for login responses, personal data pages, etc.
     */
    public static function sendNoCache(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
