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
     * @var AuthSession|null
     */
    private static ?self $instance = null;

    /**
     * In-memory user record set by {@see bindBearer()}. Lives **only** for
     * the current request — it is never persisted to `$_SESSION` and the
     * PHP session cookie is left untouched, because Bearer authentication
     * is by definition stateless on the server side (FT16 / ADR-0008).
     *
     * @var array<string,mixed>|null
     */
    private ?array $bearerUser = null;

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
     * Bind a user to the current request without touching the PHP session
     * cookie or `$_SESSION`. Used by {@see BearerAuth::resolve()} for
     * stateless agent / MCP clients (FT16 / ADR-0008). The bound user
     * lasts exactly one request — subsequent requests must re-authenticate.
     *
     * @param array<string,mixed> $user User row (normalized by `BearerAuth`).
     */
    final public function bindBearer(array $user): void
    {
        $this->bearerUser = [
            'id'        => (int)$user['id'],
            'user_id'   => (string)($user['user_id'] ?? ''),
            'user_name' => (string)($user['user_name'] ?? ''),
            'e_mail'    => (string)($user['e_mail'] ?? ''),
        ];
    }

    /**
     * Whether the current request was authenticated via Bearer token
     * (rather than the browser session cookie). Used by the CSRF
     * protection policy to skip the cookie+CSRF requirement.
     */
    final public function isBearerAuthenticated(): bool
    {
        return $this->bearerUser !== null;
    }

    /**
     * Check whether the current session is logged in. Bearer-bound users
     * count as logged-in for the duration of their request.
     *
     * @return boolean Login state.
     */
    final public function isLoggedIn(): bool
    {
        if ($this->bearerUser !== null) {
            return true;
        }
        return ($_SESSION['xion']['login_mode'] ?? '') === 'login' && $this->user() !== null;
    }

    /**
     * Get current login user information. The Bearer-bound user takes
     * precedence over `$_SESSION` so an agent request never sees a
     * stale browser cookie even when both happen to be present.
     *
     * @return array<string,mixed>|null Current user information.
     */
    final public function user(): ?array
    {
        if ($this->bearerUser !== null) {
            return $this->bearerUser;
        }
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
