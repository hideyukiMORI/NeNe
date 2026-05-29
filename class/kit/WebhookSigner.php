<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * HMAC-SHA256 webhook signing and verification.
 *
 * Implements the Stripe-style signature format:
 *   `X-Webhook-Signature: t=<unix_timestamp>,v1=<hmac_hex>`
 *
 * The signature covers `"<timestamp>.<raw_body>"` — including the timestamp
 * in the signed data prevents replay attacks where an attacker changes the
 * timestamp header while keeping a captured signature.
 *
 * ## Inbound (verifying webhooks you receive)
 *
 * ```php
 * $signer = new WebhookSigner((string)getenv('WEBHOOK_SECRET'));
 * $rawBody = file_get_contents('php://input');
 * $header  = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
 *
 * if (!$signer->verify($rawBody, $header)) {
 *     // 401 — invalid or stale signature
 * }
 * ```
 *
 * ## Outbound (signing webhooks you send)
 *
 * ```php
 * $signer    = new WebhookSigner($endpoint->secret);
 * $rawBody   = json_encode($payload, JSON_THROW_ON_ERROR);
 * $sigHeader = $signer->sign($rawBody);
 *
 * // Send via cURL / Guzzle / file_get_contents with 'X-Webhook-Signature' header
 * ```
 */
final readonly class WebhookSigner
{
    private const ALGORITHM = 'sha256';
    private const HEADER_PREFIX = 't=';
    private const SIG_PREFIX = 'v1=';

    /**
     * @param string $secret     HMAC secret shared between sender and receiver.
     * @param int    $tolerance  Maximum age of a valid timestamp in seconds. Default: 300.
     */
    public function __construct(
        private string $secret,
        private int $tolerance = 300,
    ) {
    }

    /**
     * Generate a signed header value for an outbound webhook.
     *
     * @param string   $rawBody   Raw request body (JSON string, not decoded array).
     * @param int|null $timestamp Unix timestamp; null = current time.
     *
     * @return string Header value, e.g. `t=1716800000,v1=abc123...`
     */
    public function sign(string $rawBody, ?int $timestamp = null): string
    {
        $ts  = $timestamp ?? time();
        $sig = $this->computeSignature($ts, $rawBody);
        return self::HEADER_PREFIX . $ts . ',' . self::SIG_PREFIX . $sig;
    }

    /**
     * Verify an inbound webhook signature header.
     *
     * Returns true if:
     *   - The header is parseable.
     *   - The computed HMAC matches the provided signature (constant-time).
     *   - The timestamp is within the allowed tolerance window.
     *
     * Returns false on any failure — do not distinguish the failure reason
     * to the caller (prevents information leakage).
     *
     * @param string   $rawBody   Raw request body as received (do not decode first).
     * @param string   $header    Value of the `X-Webhook-Signature` header.
     * @param int|null $now       Current Unix timestamp for testing; null = current time.
     *
     * @return bool True if valid.
     */
    public function verify(string $rawBody, string $header, ?int $now = null): bool
    {
        $parsed = $this->parseHeader($header);
        if ($parsed === null) {
            return false;
        }
        [$timestamp, $receivedSig] = $parsed;

        // Check timestamp window before signature (fast fail on stale requests)
        $current = $now ?? time();
        if (abs($current - $timestamp) > $this->tolerance) {
            return false;
        }

        $expectedSig = $this->computeSignature($timestamp, $rawBody);
        return hash_equals($expectedSig, $receivedSig);
    }

    /**
     * Generate a cryptographically secure random secret for a webhook endpoint.
     *
     * Store only the hash of this value (or the value itself in a secrets manager);
     * never log it. Return it to the endpoint owner once — it cannot be recovered.
     *
     * @return string 64-character hex string (256-bit entropy).
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac(self::ALGORITHM, "{$timestamp}.{$rawBody}", $this->secret);
    }

    /**
     * Parse `t=<ts>,v1=<sig>` → [int $timestamp, string $sig] or null on failure.
     *
     * @return array{0: int, 1: string}|null
     */
    private function parseHeader(string $header): ?array
    {
        $parts = explode(',', $header);
        $ts    = null;
        $sig   = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if (str_starts_with($part, self::HEADER_PREFIX)) {
                $raw = substr($part, strlen(self::HEADER_PREFIX));
                if (ctype_digit($raw) && $raw !== '') {
                    $ts = (int)$raw;
                }
            } elseif (str_starts_with($part, self::SIG_PREFIX)) {
                $candidate = substr($part, strlen(self::SIG_PREFIX));
                if (ctype_xdigit($candidate) && strlen($candidate) === 64) {
                    $sig = $candidate;
                }
            }
        }

        if ($ts === null || $sig === null) {
            return null;
        }
        return [$ts, $sig];
    }
}
