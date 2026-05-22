<?php

declare(strict_types=1);

namespace Nene\Xion;

use Nene\Database\UserMapper;

/**
 * Optional Bearer-token authentication for agent / MCP clients (ADR-0008).
 *
 * Stateless API clients (MCP servers, scripted agents, internal tooling)
 * cannot maintain the cookie + CSRF dance the browser path uses. This
 * helper accepts an `Authorization: Bearer <token>` header, compares it
 * to `NENE_AGENT_BEARER_TOKEN` in constant time, and on success binds
 * the configured user (`NENE_AGENT_BEARER_USER`, default `admin`) to
 * the request via {@see AuthSession::bindBearer()}. The binding is
 * in-memory for the request only — the PHP session cookie is never
 * touched.
 *
 * Resolution must be called early in the request boot (after
 * `session_start()` but before any controller / dispatcher logic).
 * `htdocs/index.php` is the canonical call site. Subsequent calls
 * short-circuit via the cached resolution.
 *
 * When the env token is unset, this helper is a no-op — the framework's
 * cookie+CSRF path runs unchanged.
 *
 * Trial source: FT16 (`docs/field-trials/2026-05-field-trial-16.md`),
 * cross-repo handoff from nene-mcp Issue #380.
 */
final class BearerAuth
{
    private const DEFAULT_USER = 'admin';

    private static bool $resolved = false;

    /**
     * Attempt Bearer authentication for this request. Idempotent: the
     * first call performs the work, subsequent calls short-circuit.
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $expectedToken = (string)(getenv('NENE_AGENT_BEARER_TOKEN') ?: '');
        if ($expectedToken === '') {
            return;
        }

        $presented = self::extractToken(self::readAuthorizationHeader());
        if ($presented === null) {
            return;
        }
        if (!hash_equals($expectedToken, $presented)) {
            return;
        }

        $userIdentifier = self::resolveUserIdentifier();
        $row = self::lookupUser($userIdentifier);
        if ($row === null) {
            // Token validated but configured user is missing — treat as
            // unauthenticated. The operator should fix `NENE_AGENT_BEARER_USER`.
            return;
        }

        AuthSession::getInstance()->bindBearer($row);
    }

    /**
     * Reset the cached resolution flag. Used by tests between assertions.
     */
    public static function reset(): void
    {
        self::$resolved = false;
    }

    /**
     * Read the inbound `Authorization` header across SAPI variations.
     * Apache + mod_php strips the header out of `$_SERVER` by default;
     * `apache_request_headers()` / `getallheaders()` still expose it.
     * `REDIRECT_HTTP_AUTHORIZATION` covers the `mod_rewrite` rewritten
     * case. The empty string means "no header on this request".
     */
    private static function readAuthorizationHeader(): string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '') {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    return (string)$value;
                }
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    return (string)$value;
                }
            }
        }
        return '';
    }

    /**
     * Parse the `Authorization: Bearer <token>` header and return the
     * token portion, or `null` when absent / malformed.
     */
    public static function extractToken(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Username this trial uses to back Bearer-authenticated requests.
     */
    private static function resolveUserIdentifier(): string
    {
        $value = getenv('NENE_AGENT_BEARER_USER');
        return $value === false ? self::DEFAULT_USER : (string)$value;
    }

    /**
     * Look up the user row by `user_id`. Returns null when missing.
     *
     * @return array<string,mixed>|null
     */
    private static function lookupUser(string $userIdentifier): ?array
    {
        try {
            $mapper = new UserMapper();
        } catch (\Throwable) {
            return null;
        }
        $row = $mapper->findRowByUserIdentifier($userIdentifier);
        return is_array($row) ? $row : null;
    }
}
