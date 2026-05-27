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
 * Cursor token for keyset (cursor-based) pagination.
 *
 * Encodes a (created_at, id) pair as a URL-safe base64url JSON token.
 * The token is opaque to callers — they pass it back as-is.
 */
final readonly class Cursor
{
    public function __construct(
        public readonly string $createdAt,
        public readonly int $id,
    ) {}

    /**
     * Encode this cursor to an opaque base64url token.
     *
     * @return string Base64url-encoded cursor token.
     */
    public function encode(): string
    {
        $json = json_encode(['c' => $this->createdAt, 'i' => $this->id], JSON_THROW_ON_ERROR);
        return strtr(base64_encode($json), '+/', '-_');
    }

    /**
     * Decode a base64url token back to a Cursor, or return null on invalid input.
     *
     * @param string $token Base64url-encoded cursor token.
     *
     * @return self|null Decoded Cursor, or null if the token is invalid.
     */
    public static function decode(string $token): ?self
    {
        if ($token === '') {
            return null;
        }
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !is_array($data) ||
            !isset($data['c'], $data['i']) ||
            !is_string($data['c']) ||
            !is_int($data['i'])
        ) {
            return null;
        }
        return new self($data['c'], $data['i']);
    }
}
