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
    protected $request;

    /**
     * Request method.
     *
     * @var string
     */
    protected $method;

    /**
     * Controller name.
     *
     * @var string
     */
    protected $controller = 'index';

    /**
     * Action name.
     *
     * @var string
     */
    protected $action = 'index';

    /**
     * Site title.
     *
     * @var string
     */
    protected $TITLE = SITE_TITLE;

    /**
     * Site header title.
     *
     * @var string
     */
    protected $HEADER_TITLE = SITE_HEADER_TITLE;

    /**
     * View management class.
     *
     * @var View
     */
    protected $VIEW;

    /**
     * Session check flag.
     *
     * @var boolean
     */
    protected $SESSION_CHECK = true;

    /**
     * Monolog information log.
     *
     * @var Logger
     */
    protected $LOGGER;

    /**
     * Undocumented variable
     *
     * @var Logger
     */
    protected $ACCESS_LOGGER;

    /**
     * Monolog error log.
     *
     * @var Logger
     */
    protected $ERROR_LOGGER;

    /**
     * Error code.
     *
     * @var ErrorCode
     */
    protected $ERROR_CODE;

    /**
     * Authentication session boundary.
     *
     * @var AuthSession
     */
    protected $AUTH_SESSION;

    /**
     * REST response factory.
     *
     * @var ApiResponse
     */
    protected $API_RESPONSE;

    /**
     * Current route context.
     *
     * @var RouteContext
     */
    protected $ROUTE_CONTEXT;

    /**
     * Rest post
     *
     * @var array
     */
    protected $REQUEST_JSON = [];

    /**
     * Referrer controller name.
     *
     * @var string
     */
    protected $refController;

    /**
     * Referrer action name.
     *
     * @var string
     */
    protected $refAction;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->request          = new Request();
        $this->method           = $_SERVER['REQUEST_METHOD'];
        $this->VIEW             = View::getInstance();
        $this->LOGGER           = Log::getInstance('information');
        $this->ACCESS_LOGGER    = Log::getInstance('access');
        $this->ERROR_LOGGER     = Log::getInstance('error');
        $this->ERROR_CODE       = Xion\ErrorCode::getInstance();
        $this->AUTH_SESSION     = Xion\AuthSession::getInstance();
        $this->API_RESPONSE     = new Xion\ApiResponse();
        $this->ROUTE_CONTEXT    = Xion\RouteContext::getInstance();
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
    final public function run()
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
     * @return mixed
     */
    protected function preAction()
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
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, $controller))) {
            $template = $controller;
        }
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
                $this->location(LOGOUT_URI);
            } else {
                Xion\JsonResponder::outputArray($this->API_RESPONSE->failure('SESSION-CLOSED'));
            }
        }
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
        if (!$this->ROUTE_CONTEXT->isRest() || !in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        if ($this->ROUTE_CONTEXT->method() === 'loginPostRest') {
            return false;
        }
        return $this->AUTH_SESSION->isLoggedIn();
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
            $uri = URI_ROOT . $uri;
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
}
