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
 * Decides whether a controller request must pass CSRF validation.
 */
class CsrfProtectionPolicy
{
    /**
     * HTTP methods that can mutate server state.
     *
     * @var array<int,string>
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Determine whether the current request must include a CSRF token.
     *
     * Bearer-authenticated requests (FT16 / ADR-0008) intentionally skip
     * CSRF — the Bearer token itself is the proof of intent, and
     * stateless agent / MCP clients have no way to maintain the
     * cookie+CSRF dance the browser path expects.
     *
     * @param RouteContext $routeContext          Current route context.
     * @param string       $requestMethod         HTTP request method.
     * @param boolean      $isLoggedIn            Login state (includes Bearer).
     * @param boolean      $isBearerAuthenticated Whether the request authenticated via Bearer.
     *
     * @return boolean Whether CSRF validation is required.
     */
    final public function requiresToken(
        RouteContext $routeContext,
        string $requestMethod,
        bool $isLoggedIn,
        bool $isBearerAuthenticated = false
    ): bool {
        if ($isBearerAuthenticated) {
            return false;
        }
        if (!$routeContext->isRest() || !in_array(strtoupper($requestMethod), self::STATE_CHANGING_METHODS, true)) {
            return false;
        }
        if ($routeContext->method() === 'loginPostRest') {
            return false;
        }
        return $isLoggedIn;
    }
}
