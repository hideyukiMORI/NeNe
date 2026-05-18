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
 * Authentication session boundary.
 */
class AuthSession
{
    /**
     * Instance to pass as a singleton.
     *
     * @var AuthSession
     */
    private static $instance;

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
    }

    /**
     * GET INSTANCE.
     *
     * @return AuthSession
     */
    final public static function getInstance(): AuthSession
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Store login user information.
     *
     * @param array<string,mixed> $user User row.
     *
     * @return array<string,mixed> Stored user information.
     */
    final public function login(array $user): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $loginUser = [
            'id'        => (int)$user['id'],
            'user_id'   => (string)$user['user_id'],
            'user_name' => (string)$user['user_name'],
            'e_mail'    => (string)$user['e_mail'],
        ];
        $_SESSION['xion']['login_mode'] = 'login';
        $_SESSION['xion']['user'] = $loginUser;
        $_SESSION['xion']['csrf_token'] = bin2hex(random_bytes(32));
        return $loginUser;
    }

    /**
     * Delete authentication session information.
     *
     * @return void
     */
    final public function logout(bool $destroySession = false): void
    {
        unset($_SESSION['xion']);

        if ($destroySession) {
            $_SESSION = [];
        }

        if ($destroySession && session_status() === PHP_SESSION_ACTIVE) {
            $this->expireSessionCookie();
            session_destroy();
        }
    }

    /**
     * Expire the browser session Cookie using the active Cookie parameters.
     *
     * @return void
     */
    private function expireSessionCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();
        $cookieOptions = [
            'expires' => time() - 3600,
            'path' => $params['path'] ?? '/',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => (string)($params['samesite'] ?? 'Lax'),
        ];
        if (($params['domain'] ?? '') !== '') {
            $cookieOptions['domain'] = $params['domain'];
        }

        setcookie(session_name(), '', $cookieOptions);
    }

    /**
     * Check whether the current session is logged in.
     *
     * @return boolean Login state.
     */
    final public function isLoggedIn(): bool
    {
        return ($_SESSION['xion']['login_mode'] ?? '') === 'login' && $this->user() !== null;
    }

    /**
     * Get current login user information.
     *
     * @return array<string,mixed>|null Current user information.
     */
    final public function user(): ?array
    {
        $user = $_SESSION['xion']['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    /**
     * Get current login user's primary key.
     *
     * @return integer|null Current user primary key.
     */
    final public function userId(): ?int
    {
        $user = $this->user();
        if ($user === null || !isset($user['id'])) {
            return null;
        }
        return (int)$user['id'];
    }

    /**
     * Get current login user's public identifier.
     *
     * @return string Current user identifier.
     */
    final public function userIdentifier(): string
    {
        $user = $this->user();
        return $user === null ? '' : (string)($user['user_id'] ?? '');
    }

    /**
     * Get the CSRF token for the current authenticated session.
     *
     * @return string CSRF token.
     */
    final public function csrfToken(): string
    {
        return (string)($_SESSION['xion']['csrf_token'] ?? '');
    }

    /**
     * Verify a submitted CSRF token against the authenticated session token.
     *
     * @param string $token Submitted CSRF token.
     *
     * @return boolean Whether the token is valid.
     */
    final public function verifyCsrfToken(string $token): bool
    {
        $sessionToken = $this->csrfToken();
        return $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    /**
     * Copy inhibit.
     *
     * @return never
     */
    final public function __clone(): never
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
