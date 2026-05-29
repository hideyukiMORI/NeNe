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

namespace Nene\Kit;

use Nene\Xion\HttpTermination;
use Nene\Xion\JsonResponder;

/**
 * Generates and verifies HMAC-SHA256-signed URLs with expiry.
 *
 * Usage:
 *   $signer = new SignedUrl($_ENV['SIGNED_URL_SECRET']);
 *
 *   // Generate
 *   $url = $signer->sign('/download/invoice', ['id' => 42]);
 *   // → /download/invoice?id=42&expires=1716854400&signature=<hex>
 *
 *   // Verify (returns bool)
 *   $ok = $signer->verify($url);
 *
 *   // Verify + throw on failure (controller guard)
 *   $signer->requireValid($url);
 */
final readonly class SignedUrl
{
    private const ALGO = 'sha256';

    public function __construct(
        private string $secret,
        private int $defaultTtl = 3600,
    ) {
    }

    /**
     * Build a signed URL.
     *
     * The original path and any $params are combined with an `expires`
     * Unix timestamp. All parameters (including `expires`) are sorted by
     * key before signing so the signature is key-order-independent.
     * A `signature` parameter is appended last.
     *
     * @param string               $path   URL path (e.g. '/download/invoice')
     * @param array<string,scalar> $params Additional query parameters.
     * @param int|null             $ttl    Seconds until expiry (default: $defaultTtl).
     * @param int|null             $now    Current timestamp (injectable for tests).
     * @return string  Full URL string with signature and expiry appended.
     */
    public function sign(
        string $path,
        array $params = [],
        ?int $ttl = null,
        ?int $now = null,
    ): string {
        $expires = ($now ?? time()) + ($ttl ?? $this->defaultTtl);
        $params['expires'] = $expires;
        ksort($params);
        $payload   = $path . '?' . http_build_query($params);
        $signature = hash_hmac(self::ALGO, $payload, $this->secret);
        $params['signature'] = $signature;
        return $path . '?' . http_build_query($params);
    }

    /**
     * Verify the signature and expiry of a signed URL.
     *
     * Returns false if:
     * - `expires` or `signature` parameters are missing
     * - `expires` is in the past
     * - The recomputed signature does not match (timing-safe comparison)
     *
     * @param string   $url Full URL string (path + query string).
     * @param int|null $now Current timestamp (injectable for tests).
     */
    public function verify(string $url, ?int $now = null): bool
    {
        $qpos = strpos($url, '?');
        if ($qpos === false) {
            return false;
        }
        $path  = substr($url, 0, $qpos);
        parse_str(substr($url, $qpos + 1), $params);

        if (!isset($params['expires'], $params['signature'])) {
            return false;
        }

        $expires   = (int) $params['expires'];
        $signature = (string) $params['signature'];

        if (($now ?? time()) > $expires) {
            return false;
        }

        unset($params['signature']);
        ksort($params);
        $payload  = $path . '?' . http_build_query($params);
        $expected = hash_hmac(self::ALGO, $payload, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Verify the URL and throw if invalid or expired.
     *
     * Throws `HttpTermination` with:
     * - 410 Gone      — URL has expired (`expires` in the past, signature still valid)
     * - 403 Forbidden — URL signature is invalid or parameters are missing
     *
     * @throws HttpTermination
     */
    public function requireValid(string $url, ?int $now = null): void
    {
        $qpos = strpos($url, '?');
        if ($qpos !== false) {
            parse_str(substr($url, $qpos + 1), $params);
            if (isset($params['expires']) && ($now ?? time()) > (int) $params['expires']) {
                // Check if signature would have been valid (optional nicety — always 410 on expired)
                throw new HttpTermination(
                    JsonResponder::response(
                        ['Result' => false, 'Data' => ['status' => 'failure', 'errorCode' => 'SIGNED-URL-EXPIRED', 'errorMessage' => 'The signed URL has expired.']],
                        410
                    )
                );
            }
        }

        if (!$this->verify($url, $now)) {
            throw new HttpTermination(
                JsonResponder::response(
                    ['Result' => false, 'Data' => ['status' => 'failure', 'errorCode' => 'SIGNED-URL-INVALID', 'errorMessage' => 'The signed URL is invalid.']],
                    403
                )
            );
        }
    }

    /**
     * Generate a cryptographically random secret suitable for signing.
     *
     * @return string 64-char hex string (32 bytes of entropy).
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
