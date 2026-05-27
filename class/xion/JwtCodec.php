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
 * JWT HS256 issuer and verifier.
 *
 * Issues and verifies compact JSON Web Tokens signed with HMAC-SHA256.
 * Implements only the HS256 algorithm to prevent algorithm-confusion attacks
 * (alg:none, RS256 with symmetric secret, etc.).
 *
 * ## Typical usage
 *
 * ```php
 * $jwt = new JwtCodec((string)getenv('NENE_JWT_SECRET'));
 *
 * // Issue (e.g. on login)
 * $token = $jwt->issue(['sub' => $userId, 'email' => $user->email]);
 *
 * // Verify (e.g. in preAction)
 * $claims = $jwt->require();   // reads Authorization: Bearer header; 401 on invalid/expired
 *
 * // Or: decode without throwing
 * $claims = $jwt->decode($token);  // null on invalid
 * ```
 *
 * Trial source: FT30 (`docs/field-trials/2026-05-field-trial-30.md`).
 */
final readonly class JwtCodec
{
    /**
     * @param string $secret     HMAC secret key. Must be at least 32 bytes for HS256.
     * @param int    $defaultTtl Default token lifetime in seconds. Default: 3600 (1 hour).
     */
    public function __construct(
        private string $secret,
        private int $defaultTtl = 3600,
    ) {
    }

    /**
     * Issue a new JWT with the given claims.
     *
     * `iat` and `exp` are set automatically if not provided in $claims.
     * All other standard claims (sub, email, etc.) are passed through as-is.
     *
     * @param array<string,mixed> $claims  Payload claims.
     * @param int|null            $ttl     Override lifetime in seconds; null uses defaultTtl.
     *
     * @return string Compact JWT string (header.payload.signature).
     */
    public function issue(array $claims, ?int $ttl = null): string
    {
        $now = time();
        $claims['iat'] = $claims['iat'] ?? $now;
        $claims['exp'] = $claims['exp'] ?? ($now + ($ttl ?? $this->defaultTtl));

        $headerJson  = json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR);
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $header  = $this->base64url((string)$headerJson);
        $payload = $this->base64url((string)$payloadJson);
        $sig     = $this->sign($header . '.' . $payload);

        return $header . '.' . $payload . '.' . $sig;
    }

    /**
     * Decode and verify a JWT string.
     *
     * Returns the decoded claims array on success.
     * Returns null if the token is missing, malformed, has a wrong signature,
     * expired, or uses a non-HS256 algorithm.
     *
     * @param string $token Compact JWT string.
     *
     * @return array<string,mixed>|null Decoded claims, or null on any failure.
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Verify signature using constant-time comparison
        $expectedSig = $this->sign($headerB64 . '.' . $payloadB64);
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        // Decode header and verify algorithm
        $headerDecoded = $this->base64urlDecode($headerB64);
        if ($headerDecoded === '') {
            return null;
        }
        $header = $this->jsonDecode($headerDecoded);
        if ($header === null || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        // Decode payload
        $payloadDecoded = $this->base64urlDecode($payloadB64);
        if ($payloadDecoded === '') {
            return null;
        }
        $claims = $this->jsonDecode($payloadDecoded);
        if ($claims === null) {
            return null;
        }

        // Validate exp (required — always reject tokens without exp)
        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            return null;
        }
        if (time() >= $claims['exp']) {
            return null;    // expired
        }

        // Validate nbf if present
        if (isset($claims['nbf']) && is_int($claims['nbf']) && time() < $claims['nbf']) {
            return null;    // not yet valid
        }

        return $claims;
    }

    /**
     * Read the Authorization: Bearer header from the current request and verify the JWT.
     *
     * Throws HttpTermination(401) if the header is absent, malformed, or invalid.
     * Returns the decoded claims on success.
     *
     * @return array<string,mixed> Decoded JWT claims.
     */
    public function require(): array
    {
        $header = $this->readBearerHeader();
        if ($header === null) {
            $this->unauthorized('Authorization: Bearer header is required.');
        }

        $claims = $this->decode($header);
        if ($claims === null) {
            $this->unauthorized('Token is invalid or expired.');
        }

        return $claims;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * @return never
     */
    private function unauthorized(string $message): never
    {
        header('WWW-Authenticate: Bearer');
        throw new HttpTermination(
            JsonResponder::response(
                ['Result' => false, 'Data' => [
                    'status'       => 'failure',
                    'errorCode'    => 'JWT-INVALID',
                    'errorMessage' => $message,
                ]],
                401
            )
        );
    }

    private function sign(string $data): string
    {
        return $this->base64url(
            (string)hash_hmac('sha256', $data, $this->secret, binary: true)
        );
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonDecode(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function readBearerHeader(): ?string
    {
        // Try Apache headers
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return $this->extractBearerToken($value);
                }
            }
        }

        // Try getallheaders() (some PHP SAPI configs)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return $this->extractBearerToken($value);
                }
            }
        }

        // Fallback to $_SERVER
        $raw = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        return $this->extractBearerToken($raw);
    }

    private function extractBearerToken(string $header): ?string
    {
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }
}
