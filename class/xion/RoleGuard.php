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
 * Role-based access control helper for JWT-authenticated controllers.
 *
 * Works with the `role` claim in JWT payloads produced by JwtCodec.
 * Roles are plain strings (e.g. 'admin', 'user', 'moderator').
 *
 * ## Typical usage
 *
 * ```php
 * protected function preAction(): void
 * {
 *     $this->claims = (new JwtCodec(getenv('NENE_JWT_SECRET')))->require();
 * }
 *
 * // Endpoint restricted to 'admin'
 * public function deletePostRest(): array
 * {
 *     RoleGuard::require('admin', $this->claims);   // 403 if not admin
 *     // ...
 * }
 *
 * // Endpoint open to 'editor' or 'admin'
 * public function updatePostRest(): array
 * {
 *     RoleGuard::requireAny(['editor', 'admin'], $this->claims);
 *     // ...
 * }
 * ```
 */
final class RoleGuard
{
    /**
     * Assert that the claims contain the required role.
     *
     * Throws HttpTermination(403 Forbidden) if the `role` claim is absent
     * or does not match $requiredRole.
     *
     * @param string              $requiredRole Role name to require (e.g. 'admin').
     * @param array<string,mixed> $claims       Decoded JWT claims from JwtCodec::require().
     *
     * @return void
     * @throws HttpTermination 403 when the role requirement is not met.
     */
    public static function require(string $requiredRole, array $claims): void
    {
        if (!self::has($requiredRole, $claims)) {
            self::forbidden("This action requires the '{$requiredRole}' role.");
        }
    }

    /**
     * Assert that the claims contain at least one of the required roles.
     *
     * Throws HttpTermination(403 Forbidden) if the `role` claim matches none
     * of $requiredRoles.
     *
     * @param list<string>        $requiredRoles One or more acceptable roles.
     * @param array<string,mixed> $claims        Decoded JWT claims.
     *
     * @return void
     * @throws HttpTermination 403 when none of the roles are present.
     */
    public static function requireAny(array $requiredRoles, array $claims): void
    {
        foreach ($requiredRoles as $role) {
            if (self::has($role, $claims)) {
                return;
            }
        }
        $list = implode(', ', $requiredRoles);
        self::forbidden("This action requires one of the following roles: {$list}.");
    }

    /**
     * Check whether the claims contain the given role (no side effects).
     *
     * @param string              $role   Role name to check.
     * @param array<string,mixed> $claims Decoded JWT claims.
     *
     * @return bool True if the claims carry the role.
     */
    public static function has(string $role, array $claims): bool
    {
        $claimRole = $claims['role'] ?? '';
        return is_string($claimRole) && $claimRole === $role;
    }

    /**
     * @return never
     */
    private static function forbidden(string $message): never
    {
        throw new HttpTermination(
            JsonResponder::response(
                ['Result' => false, 'Data' => [
                    'status'       => 'failure',
                    'errorCode'    => 'FORBIDDEN',
                    'errorMessage' => $message,
                ]],
                403
            )
        );
    }
}
