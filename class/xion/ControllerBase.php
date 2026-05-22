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

use Monolog\Logger;
use Nene\Xion as Xion;

/**
 * Controller abstract class.
 *
 * Super class of all controller.
 * Includes basic controller behavior.
 *
 * @author  HideyukiMORI
 */
abstract class ControllerBase
{
    /**
     * Request.
     *
     * @var Request
     */
    protected Request $request;

    /**
     * Request method.
     *
     * @var string
     */
    protected string $method;

    /**
     * Site title.
     *
     * @var string
     */
    protected string $TITLE;

    /**
     * Site header title.
     *
     * @var string
     */
    protected string $HEADER_TITLE;

    /**
     * View management class.
     *
     * @var View
     */
    protected View $VIEW;

    /**
     * Session check flag.
     *
     * @var boolean
     */
    protected bool $SESSION_CHECK = true;

    /**
     * Monolog information log.
     *
     * @var Logger
     */
    protected Logger $LOGGER;

    /**
     * Access log.
     *
     * @var Logger
     */
    protected Logger $ACCESS_LOGGER;

    /**
     * Monolog error log.
     *
     * @var Logger
     */
    protected Logger $ERROR_LOGGER;

    /**
     * Error code.
     *
     * @var ErrorCode
     */
    protected ErrorCode $ERROR_CODE;

    /**
     * Authentication session boundary.
     *
     * @var AuthSession
     */
    protected AuthSession $AUTH_SESSION;

    /**
     * REST response factory.
     *
     * @var ApiResponse
     */
    protected ApiResponse $API_RESPONSE;

    /**
     * Current route context.
     *
     * @var RouteContext
     */
    protected RouteContext $ROUTE_CONTEXT;

    /**
     * CSRF validation decision boundary.
     *
     * @var CsrfProtectionPolicy
     */
    private CsrfProtectionPolicy $csrfProtectionPolicy;

    /**
     * Rest post
     *
     * @var array
     */
    protected array $REQUEST_JSON = [];

    /**
     * Referrer controller name.
     *
     * @var string
     */
    protected string $refController;

    /**
     * Referrer action name.
     *
     * @var string
     */
    protected string $refAction;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->request          = new Request();
        $this->method           = $_SERVER['REQUEST_METHOD'];
        $this->TITLE            = SITE_TITLE;
        $this->HEADER_TITLE     = SITE_HEADER_TITLE;
        $this->VIEW             = View::getInstance();
        $this->LOGGER           = Log::getInstance('information');
        $this->ACCESS_LOGGER    = Log::getInstance('access');
        $this->ERROR_LOGGER     = Log::getInstance('error');
        $this->ERROR_CODE       = Xion\ErrorCode::getInstance();
        $this->AUTH_SESSION     = Xion\AuthSession::getInstance();
        $this->API_RESPONSE     = new Xion\ApiResponse();
        $this->ROUTE_CONTEXT    = Xion\RouteContext::getInstance();
        $this->csrfProtectionPolicy = new Xion\CsrfProtectionPolicy();
        $this->refController    = $_SESSION['global']['referer']['controller'] ?? '';
        $this->refAction        = $_SESSION['global']['referer']['action'] ?? '';
    }

    /**
     * run
     *
     * Controller execution.
     *
     * @return void
     */
    final public function run(): void
    {
        $controller = $this->ROUTE_CONTEXT->controller();
        $action = $this->ROUTE_CONTEXT->action();
        if ($controller != 'debug') {
            $_SESSION['global']['referer']['controller']    = $controller;
            $_SESSION['global']['referer']['action']        = $action;
            $this->ACCESS_LOGGER->info(
                'ACCESS : ' . $controller . '::' . $action,
                [
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    $_SERVER['HTTP_REFERER'] ?? '',
                ]
            );
        }
        if ($this->ROUTE_CONTEXT->isRest() && in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $this->REQUEST_JSON = Xion\JsonResponder::inputJsonToArray();
        } elseif ($this->ROUTE_CONTEXT->isAction()) {
            $this->setTemplate();
        }
        $this->preAction();

        if ($this->SESSION_CHECK) {
            $this->sessionCheck();
        }
        if ($this->requiresCsrfProtection() && !$this->AUTH_SESSION->verifyCsrfToken($this->csrfTokenFromRequest())) {
            Xion\JsonResponder::outputArray($this->API_RESPONSE->failure('CSRF-TOKEN-INVALID'));
        }

        $methodName = $this->ROUTE_CONTEXT->method();
        $return = $this->$methodName();

        if ($this->ROUTE_CONTEXT->isRest()) {
            Xion\JsonResponder::outputArray($return);
        } else {
            $this->setCSS();
            $this->setJS();
            $this->VIEW->setTitle($this->TITLE)
                ->setString('t_header_title', $this->HEADER_TITLE)
                ->setString('t_copyright', COPYRIGHT)
                ->setString('t_copyright_url', COPYRIGHT_URL)
                ->setString('t_root', URI_ROOT)
                ->setString('t_appVersion', VERSION)
                ->setString('t_controller', $controller)
                ->setString('t_action', $action)
                ->setString('t_controller_action', $controller . '_' . $action)
                ->setInteger('t_debugMode', DEBUG_MODE)
                ->setString('t_login_mode', $this->SESSION_CHECK ? '1' : '0')
                ->execute();
        }
    }

    /**
     * preAction
     * Executed before the main process of run.
     *
     * @return void
     */
    protected function preAction(): void
    {
    }

    /**
     * Set title.
     * Sets the page title property of the controller.
     *
     * @param string $title Page title.
     *
     * @return void
     */
    final protected function setTitle(string $title): void
    {
        $this->TITLE = $title;
    }

    /**
     * setTemplate
     *
     * Template loader.
     * The template to be used is determined from the controller name and action name and set automatically.
     *
     * @return void
     */
    final protected function setTemplate(): void
    {
        $controller = $this->ROUTE_CONTEXT->controller();
        $action = $this->ROUTE_CONTEXT->action();
        $template = 'common';
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, $controller . '/' . $template))) {
            $template = $controller . '/' . $template;
        }
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, $controller . '/' . $action))) {
            $template = $controller . '/' . $action;
        }
        $this->VIEW->setTemplate($template . '.tpl');
    }

    /**
     * setCSS
     *
     * Style sheet loader.
     * The style sheet to be used is determined from the controller name and action name and set automatically.
     *
     * @return void
     */
    final protected function setCSS(): void
    {
        $controller = $this->ROUTE_CONTEXT->controller();
        $action = $this->ROUTE_CONTEXT->action();
        if (file_exists(sprintf('%scss/%s.css', DOCUMENT_ROOT, $controller))) {
            $this->VIEW->addCSS($controller);
        }
        if (file_exists(sprintf('%scss/%s/common.css', DOCUMENT_ROOT, $controller))) {
            $this->VIEW->addCSS($controller . '/common');
        }
        if (file_exists(sprintf('%scss/%s/%s.css', DOCUMENT_ROOT, $controller, $action))) {
            $this->VIEW->addCSS($controller . '/' . $action);
        }
    }

    /**
     * setJS
     *
     * Javascript loader.
     * The javascript to be used is determined from the controller name and action name and automatically set.
     *
     * @return void
     */
    final protected function setJS(): void
    {
        $controller = $this->ROUTE_CONTEXT->controller();
        $action = $this->ROUTE_CONTEXT->action();
        if (file_exists(sprintf('%sjs/%s.js', DOCUMENT_ROOT, $controller))) {
            $this->VIEW->addJS($controller);
        }
        if (file_exists(sprintf('%sjs/%s/common.js', DOCUMENT_ROOT, $controller))) {
            $this->VIEW->addJS($controller . '/common');
        }
        if (file_exists(sprintf('%sjs/%s/%s.js', DOCUMENT_ROOT, $controller, $action))) {
            $this->VIEW->addJS($controller . '/' . $action);
        }
    }

    /**
     * sessionCheck
     *
     * Check the login status of the request.
     * Since it is a simple thing, please set up as needed.
     *
     * @return void
     */
    final protected function sessionCheck(): void
    {
        if (!$this->AUTH_SESSION->isLoggedIn()) {
            $this->logout();
            if (!$this->ROUTE_CONTEXT->isRest()) {
                $this->location($this->unauthorizedRedirect());
            } else {
                Xion\JsonResponder::outputArray($this->API_RESPONSE->failure('SESSION-CLOSED'));
            }
        }
    }

    /**
     * Return the URI an unauthenticated HTML visitor is redirected to.
     *
     * Override in a subclass to send specific protected sections to a
     * dedicated login page (for example `/admin/login` for admin
     * controllers). The default value is the framework-wide `LOGOUT_URI`
     * constant, which can be overridden globally via the `NENE_LOGOUT_URI`
     * environment variable.
     *
     * @return string
     */
    protected function unauthorizedRedirect(): string
    {
        return LOGOUT_URI;
    }

    /**
     * logout
     *
     * Delete the session information and log out.
     *
     * @return void
     */
    final protected function logout(bool $destroySession = false): void
    {
        $this->AUTH_SESSION->logout($destroySession);
    }

    /**
     * Determine whether the current REST request must include a CSRF token.
     *
     * @return boolean Whether CSRF validation is required.
     */
    final protected function requiresCsrfProtection(): bool
    {
        return $this->csrfProtectionPolicy->requiresToken(
            $this->ROUTE_CONTEXT,
            $this->method,
            $this->AUTH_SESSION->isLoggedIn(),
            $this->AUTH_SESSION->isBearerAuthenticated()
        );
    }

    /**
     * Read the CSRF token from the request header.
     *
     * @return string Submitted token.
     */
    final protected function csrfTokenFromRequest(): string
    {
        return (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    /**
     * Return the current login user's primary key.
     *
     * Convenience for controllers that read or write rows scoped to the
     * authenticated user. Equivalent to `(int)$this->AUTH_SESSION->userId()`
     * but shorter and centrally typed.
     *
     * @return int User primary key.
     */
    final protected function getLoginUserId(): int
    {
        return (int)$this->AUTH_SESSION->userId();
    }

    /**
     * Return the current session's CSRF token for embedding in an HTML form.
     *
     * Use this in an HTML action to pass the token to the template:
     *
     *     $this->VIEW->setString('t_csrf_token', $this->csrfToken());
     *
     * In REST controllers, the token is automatically verified against the
     * `X-CSRF-Token` header by the framework dispatcher; HTML actions must
     * embed it in a hidden field and call {@see requireCsrfFromPost()}.
     *
     * @return string Session CSRF token.
     */
    final protected function csrfToken(): string
    {
        return $this->AUTH_SESSION->csrfToken();
    }

    /**
     * Verify the CSRF token submitted via a POST form field.
     *
     * Low-level helper: returns a boolean. Prefer {@see requireCsrfFromPost()}
     * in new code — it cannot be silently ignored if the caller forgets to
     * branch on the return value. Reach for this method only when the handler
     * needs to recover from a CSRF failure rather than terminate.
     *
     * @param string $field POST field name carrying the token (default `csrf_token`).
     *
     * @return boolean Whether the submitted token matched the session token.
     */
    final protected function verifyCsrfFromPost(string $field = 'csrf_token'): bool
    {
        $token = (string)($this->request->getPost($field) ?? '');
        return $this->AUTH_SESSION->verifyCsrfToken($token);
    }

    /**
     * Verify the CSRF token from POST and terminate the request on failure.
     *
     * Pairs with {@see csrfToken()}: the template emits the token as a hidden
     * input, and the handler calls this method before performing any
     * state-changing work:
     *
     *     public function deleteAction(): void
     *     {
     *         if ($this->method !== 'POST') {
     *             return;
     *         }
     *         $this->requireCsrfFromPost();
     *         // ... safe to perform the destructive write
     *     }
     *
     * On failure the response is emitted directly and the dispatch is
     * terminated via `HttpTermination`:
     *
     * - REST callers receive a 403 `CSRF-TOKEN-INVALID` JSON envelope (matching
     *   the automatic check in {@see run()} for `*Rest` handlers).
     * - HTML callers receive a 403 page from `csrf.html` at the project root.
     *
     * Use {@see verifyCsrfFromPost()} directly only when the handler needs to
     * react to the failure (e.g. re-render the form with field-level errors)
     * instead of terminating.
     *
     * @param string $field POST field name carrying the token (default `csrf_token`).
     *
     * @return void
     */
    final protected function requireCsrfFromPost(string $field = 'csrf_token'): void
    {
        if ($this->verifyCsrfFromPost($field)) {
            return;
        }
        if ($this->ROUTE_CONTEXT->isRest()) {
            Xion\JsonResponder::outputArray($this->API_RESPONSE->failure('CSRF-TOKEN-INVALID'));
        }
        throw new Xion\HttpTermination(
            Xion\HttpResponse::html((string)file_get_contents(DIR_ROOT . '/csrf.html'), 403)
        );
    }

    /**
     * Move URL.
     *
     * Moves to the specified URL.
     *
     * @param string  $uri  URI.
     * @param boolean $flag In service or not (true = inside service | false = outside).
     *
     * @return never
     */
    final protected function location(string $uri, bool $flag = true): never
    {
        if ($flag) {
            $uri = rtrim(URI_ROOT, '/') . '/' . ltrim($uri, '/');
        }
        throw new Xion\HttpTermination(Xion\HttpResponse::redirect($uri));
    }

    /**
     * NotFound.
     *
     * Output 404 page.
     *
     * @return never
     */
    final protected function notFound(): never
    {
        throw new Xion\HttpTermination(Xion\HttpResponse::html((string)file_get_contents(DIR_ROOT . '/404.html'), 404));
    }

    /**
     * Send a binary file response and terminate dispatch.
     *
     * `*Rest` and `*Action` handlers normally return arrays or `void`;
     * `ControllerBase::run()` then JSON-encodes or Smarty-renders the
     * response. To serve a binary file (PDF, image, generated CSV)
     * inline from a REST handler, call `$this->sendFile($path, $mime)`
     * — it throws {@see Xion\HttpTermination} carrying a
     * {@see Xion\HttpResponse::file()} that the top-level catch in
     * `htdocs/index.php` emits unchanged.
     *
     * The file must exist and be readable. Pass `$downloadName` to add
     * `Content-Disposition: attachment; filename=...`; omit it for inline
     * rendering (browser previews PDFs / images instead of saving).
     *
     * See FT12 F-2 / ADR-N/A. Option (c) of the trial report.
     *
     * @return never
     */
    final protected function sendFile(string $path, string $mime, ?string $downloadName = null): never
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->notFound();
        }
        throw new Xion\HttpTermination(
            Xion\HttpResponse::file((string)file_get_contents($path), $mime, $downloadName)
        );
    }
}
