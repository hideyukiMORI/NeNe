<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\JwtCodec;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JwtCodec.
 *
 * `require()` reads Authorization headers via superglobals / SAPI functions
 * and is therefore not tested here. All security-relevant logic is exercised
 * through `issue()` and `decode()`.
 */
final class JwtCodecTest extends TestCase
{
    private JwtCodec $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtCodec('super-secret-key-that-is-at-least-32-bytes-long');
    }

    public function testIssueReturnsThreePartToken(): void
    {
        $token = $this->jwt->issue(['sub' => '42']);
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'JWT must have exactly three dot-separated parts');
    }

    public function testDecodeValidatesOwnToken(): void
    {
        $token = $this->jwt->issue(['sub' => '42', 'email' => 'user@example.com']);
        $claims = $this->jwt->decode($token);
        self::assertNotNull($claims, 'decode() must accept a freshly issued token');
    }

    public function testDecodeReturnsCorrectClaims(): void
    {
        $token  = $this->jwt->issue(['sub' => '99', 'email' => 'alice@example.com']);
        $claims = $this->jwt->decode($token);
        self::assertIsArray($claims);
        assert($claims !== null);   // narrow type for static analysis
        self::assertSame('99', $claims['sub']);
        self::assertSame('alice@example.com', $claims['email']);
        self::assertArrayHasKey('iat', $claims);
        self::assertArrayHasKey('exp', $claims);
        self::assertIsInt($claims['iat']);
        self::assertIsInt($claims['exp']);
    }

    public function testDecodeReturnsNullForExpiredToken(): void
    {
        // Issue a token that is already expired (exp in the past)
        $token  = $this->jwt->issue(['sub' => '1'], ttl: -1);
        $claims = $this->jwt->decode($token);
        self::assertNull($claims, 'decode() must reject an expired token');
    }

    public function testDecodeReturnsNullForWrongSignature(): void
    {
        $token = $this->jwt->issue(['sub' => '1']);
        // Tamper: flip one character in the signature (last part)
        $parts         = explode('.', $token);
        $parts[2]      = $parts[2][0] === 'A' ? 'B' . substr($parts[2], 1) : 'A' . substr($parts[2], 1);
        $tamperedToken = implode('.', $parts);

        self::assertNull($this->jwt->decode($tamperedToken), 'decode() must reject wrong signature');
    }

    public function testDecodeReturnsNullForAlgNoneToken(): void
    {
        // Build a manually crafted alg:none token
        $encodeB64url = static function (string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $header  = $encodeB64url((string)json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $encodeB64url((string)json_encode(['sub' => '1', 'exp' => time() + 3600, 'iat' => time()]));
        $token   = $header . '.' . $payload . '.';

        self::assertNull($this->jwt->decode($token), 'decode() must reject alg:none tokens');
    }

    public function testDecodeReturnsNullForMissingExp(): void
    {
        // Craft a token without exp by building it manually using the same secret
        // We issue normally then strip exp from the payload to simulate the case
        $secret = 'super-secret-key-that-is-at-least-32-bytes-long';
        $encodeB64url = static function (string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $header  = $encodeB64url((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $encodeB64url((string)json_encode(['sub' => '1', 'iat' => time()]));
        $sig     = rtrim(strtr(base64_encode(
            (string)hash_hmac('sha256', $header . '.' . $payload, $secret, binary: true)
        ), '+/', '-_'), '=');
        $token   = $header . '.' . $payload . '.' . $sig;

        self::assertNull($this->jwt->decode($token), 'decode() must reject tokens without exp');
    }

    public function testDecodeReturnsNullForFutureNbf(): void
    {
        $token = $this->jwt->issue(['sub' => '1', 'nbf' => time() + 3600]);
        self::assertNull($this->jwt->decode($token), 'decode() must reject token whose nbf is in the future');
    }

    public function testDecodeReturnsNullForEmptyString(): void
    {
        self::assertNull($this->jwt->decode(''), 'decode() must return null for empty string');
    }

    public function testDecodeReturnsNullForInvalidBase64(): void
    {
        self::assertNull($this->jwt->decode('!!!.!!!.!!!'), 'decode() must return null for invalid base64');
    }

    public function testIssueWithExplicitExpHonorsIt(): void
    {
        $explicitExp = time() + 7200;
        $token       = $this->jwt->issue(['sub' => '1', 'exp' => $explicitExp]);
        $claims      = $this->jwt->decode($token);
        self::assertIsArray($claims);
        assert($claims !== null);   // narrow type for static analysis
        self::assertSame($explicitExp, $claims['exp'], 'issue() must honour a caller-provided exp');
    }

    public function testDecodeReturnsNullWithDifferentSecret(): void
    {
        $other  = new JwtCodec('completely-different-secret-key-that-is-32-bytes');
        $token  = $this->jwt->issue(['sub' => '1']);
        $claims = $other->decode($token);
        self::assertNull($claims, 'decode() must reject tokens signed with a different secret');
    }
}
