<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Open-redirect guard for controllers that need to redirect to an
 * external URI (#408, eval report PR #401 § 4).
 *
 * Stateless agent / form callers regularly accept a `return_to` URI
 * from user input and forward to it after login or after a wizard
 * step. Without a guard, that pattern is a classic open-redirect
 * vector for phishing campaigns. This helper enforces an env-driven
 * allowlist so only known external hosts can be the destination.
 *
 * Usage from a controller:
 *
 *     RedirectGuard::ensureExternalAllowed($candidateUri);
 *     // …safe to redirect to $candidateUri…
 *
 * Operator config:
 *
 *     NENE_ALLOWED_EXTERNAL_REDIRECTS=example.com,partner.example.org
 *
 * Empty / unset env disallows all external redirects (fail-closed).
 * The guard does **not** decide on its own that a redirect should
 * happen — it only validates that, given a redirect is about to
 * happen, the destination is allow-listed.
 */
final class RedirectGuard
{
    /**
     * Throw `HttpTermination(403)` if `$uri`'s host is not allow-listed.
     */
    public static function ensureExternalAllowed(string $uri): void
    {
        if (!self::isExternalAllowed($uri)) {
            throw new HttpTermination(HttpResponse::html('Forbidden', 403));
        }
    }

    /**
     * Predicate form — useful in unit tests and policy decisions that
     * want to inspect the result without raising.
     */
    public static function isExternalAllowed(string $uri): bool
    {
        $host = parse_url($uri, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $allowed = self::allowedHosts();
        if ($allowed === []) {
            return false;
        }
        return in_array(strtolower($host), $allowed, true);
    }

    /**
     * Parse `NENE_ALLOWED_EXTERNAL_REDIRECTS` into a normalized
     * lowercase host list. Trimmed and empty entries are dropped so
     * `host1, ,host2` parses to `[host1, host2]`.
     *
     * @return array<int,string>
     */
    public static function allowedHosts(): array
    {
        $raw = getenv('NENE_ALLOWED_EXTERNAL_REDIRECTS');
        $raw = $raw === false ? '' : (string)$raw;
        if ($raw === '') {
            return [];
        }
        $hosts = array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $raw)
        );
        return array_values(array_filter(
            $hosts,
            static fn (string $host): bool => $host !== ''
        ));
    }
}
