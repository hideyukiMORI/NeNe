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
     * @param RouteContext $routeContext  Current route context.
     * @param string       $requestMethod HTTP request method.
     * @param boolean      $isLoggedIn    Login state for the current session.
     *
     * @return boolean Whether CSRF validation is required.
     */
    final public function requiresToken(RouteContext $routeContext, string $requestMethod, bool $isLoggedIn): bool
    {
        if (!$routeContext->isRest() || !in_array(strtoupper($requestMethod), self::STATE_CHANGING_METHODS, true)) {
            return false;
        }
        if ($routeContext->method() === 'loginPostRest') {
            return false;
        }
        return $isLoggedIn;
    }
}
