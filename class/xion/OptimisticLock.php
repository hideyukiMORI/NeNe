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
 * HTTP conditional write helpers for optimistic locking.
 *
 * Implements ETag / If-Match semantics: clients read a version,
 * then send it back on write via the If-Match header. Concurrent
 * writes fail with 412 Precondition Failed instead of silently
 * overwriting each other.
 *
 * ## Typical usage
 *
 * GET handler:
 *   OptimisticLock::sendETag($doc->version);
 *   return $this->API_RESPONSE->success(['doc' => ...]);
 *
 * PUT/PATCH handler:
 *   $version = OptimisticLock::requireVersion();     // 428 if absent
 *   $updated = $mapper->updateIfVersion($id, $data, $version);
 *   if ($updated === null) {
 *       OptimisticLock::conflict();                  // 412 if mismatch
 *   }
 *   OptimisticLock::sendETag($updated->version);
 *   return $this->API_RESPONSE->success(...);
 */
final class OptimisticLock
{
    /**
     * Parse an If-Match header value into an integer version number.
     *
     * Accepted forms: `"v5"`, `"5"`, `v5`, `5`.
     * Returns null if the header is absent, empty, or unparseable.
     *
     * @param string|null $header Value of the If-Match header, or null if absent.
     *
     * @return int|null Parsed version, or null if unparseable.
     */
    public static function parseIfMatch(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        // Strip surrounding quotes and leading 'v'
        $stripped = trim($header, '"\'');
        $stripped = ltrim($stripped, 'v');
        if (!ctype_digit($stripped) || $stripped === '') {
            return null;
        }
        $version = (int)$stripped;
        return $version >= 1 ? $version : null;
    }

    /**
     * Format a version integer as an ETag header value.
     *
     * @param int $version Resource version number (>= 1).
     *
     * @return string ETag value, e.g. `"v5"`.
     */
    public static function etagFor(int $version): string
    {
        return '"v' . $version . '"';
    }

    /**
     * Emit an ETag response header for the current request.
     *
     * Call this after every successful GET, PUT, or PATCH that returns
     * a versioned resource so the client can use the value in future
     * If-Match headers.
     *
     * @param int $version Resource version number.
     *
     * @return void
     */
    public static function sendETag(int $version): void
    {
        header('ETag: ' . self::etagFor($version));
    }

    /**
     * Read and require the If-Match header from the current request.
     *
     * Throws HttpTermination(428 Precondition Required) if the header
     * is absent or unparseable — following RFC 9110 §13.1.
     *
     * @return int Parsed version number.
     */
    public static function requireVersion(): int
    {
        $header = $_SERVER['HTTP_IF_MATCH'] ?? null;
        $version = self::parseIfMatch($header);
        if ($version === null) {
            throw new HttpTermination(
                JsonResponder::response(
                    ['Result' => false, 'Data' => [
                        'status'       => 'failure',
                        'errorCode'    => 'PRECONDITION-REQUIRED',
                        'errorMessage' => 'If-Match header is required for this operation.',
                    ]],
                    428
                )
            );
        }
        return $version;
    }

    /**
     * Throw a 412 Precondition Failed response.
     *
     * Call this when a conditional UPDATE returned zero affected rows
     * (meaning a concurrent writer already changed the version).
     *
     * @return never
     */
    public static function conflict(): never
    {
        throw new HttpTermination(
            JsonResponder::response(
                ['Result' => false, 'Data' => [
                    'status'       => 'failure',
                    'errorCode'    => 'PRECONDITION-FAILED',
                    'errorMessage' => 'The resource was modified by another request. Fetch the latest version and retry.',
                ]],
                412
            )
        );
    }
}
