<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 7.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

use Nene\Model          as Model;
use Nene\Database       as Database;
use Nene\Xion           as Xion;
use Nene\Func           as Func;

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
     * Rest post
     *
     * @var array
     */
    protected $REQUEST_JSON;

    /**
     * Json format at rest.
     *
     * @var string
     */
    protected $OUTPUT_JSON_STYLE = 'json';

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
        $this->method           = $_SERVER["REQUEST_METHOD"];
        $this->VIEW             = View::getInstance();
        $this->LOGGER           = Log::getInstance('information');
        $this->ACCESS_LOGGER    = Log::getInstance('access');
        $this->ERROR_LOGGER     = Log::getInstance('error');
        $this->ERROR_CODE       = Xion\ErrorCode::getInstance();
        $this->refController    = $_SESSION['global']['referer']['controller'] ?? '';
        $this->refAction        = $_SESSION['global']['referer']['action'] ?? '';
    }

    /**
     * run
     *
     * Controller execution.
     *
     * @return mix
     */
    final public function run()
    {
        if (APP_CONTROLLER != 'debug') {
            $_SESSION['global']['referer']['controller']    = APP_CONTROLLER;
            $_SESSION['global']['referer']['action']        = APP_ACTION;
            $this->ACCESS_LOGGER->addInfo(
                'ACCESS : ' . APP_CONTROLLER . '::' . APP_ACTION,
                [
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    $_SERVER['HTTP_REFERER'] ?? ''
                ]
            );
        }
        if (APP_ACTION_MODE == 'Rest' && $this->method == 'POST') {
            $this->REQUEST_JSON = Func\Json::inputPostJsonToArray();
        } elseif (APP_ACTION_MODE == 'Action') {
            $this->setTemplate();
        }
        $this->preAction();

        if ($this->SESSION_CHECK) {
            $this->sessionCheck();
        }

        $methodName = sprintf('%s' . APP_ACTION_MODE, APP_ACTION);
        $return = $this->$methodName();

        if (APP_ACTION_MODE == 'Rest') {
            Func\Json::outputArrayToJson(
                $return,
                $this->OUTPUT_JSON_STYLE,
                filter_input(INPUT_GET, 'callback') ?: '',
                $this->SESSION_CHECK
            );
            return $return;
        } else {
            $this->setCSS();
            $this->setJS();
            $this->VIEW->setTitle($this->TITLE)
                ->setString('t_header_title', $this->HEADER_TITLE)
                ->setString('t_copyright', COPYRIGHT)
                ->setString('t_copyright_url', COPYRIGHT_URL)
                ->setString('t_root', URI_ROOT)
                ->setString('t_appVersion', VERSION)
                ->setString('t_controller', APP_CONTROLLER)
                ->setString('t_action', APP_ACTION)
                ->setString('t_controller_action', APP_CONTROLLER . '_' . APP_ACTION)
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
     * Set output format of json
     *
     * @param string $style Format is jsonp or json.
     *
     * @return  void
     *
     */
    final protected function setOutputJsonStyle(string $style = 'jsonp'): void
    {
        $this->OUTPUT_JSON_STYLE = $style == 'jsonp' ? 'jsonp' : 'json';
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
        $template = 'common';
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, APP_CONTROLLER))) {
            $template = APP_CONTROLLER;
        }
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, APP_CONTROLLER . '/' . $template))) {
            $template = APP_CONTROLLER . '/' . $template;
        }
        if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, APP_CONTROLLER . '/' . APP_ACTION))) {
            $template = APP_CONTROLLER . '/' . APP_ACTION;
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
        if (file_exists(sprintf('%scss/%s.css', DOCUMENT_ROOT, APP_CONTROLLER))) {
            $this->VIEW->addCSS(APP_CONTROLLER);
        }
        if (file_exists(sprintf('%scss/%s/common.css', DOCUMENT_ROOT, APP_CONTROLLER))) {
            $this->VIEW->addCSS(APP_CONTROLLER . '/common');
        }
        if (file_exists(sprintf('%scss/%s/%s.css', DOCUMENT_ROOT, APP_CONTROLLER, APP_ACTION))) {
            $this->VIEW->addCSS(APP_CONTROLLER . '/' . APP_ACTION);
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
        if (file_exists(sprintf('%sjs/%s.js', DOCUMENT_ROOT, APP_CONTROLLER))) {
            $this->VIEW->addJS(APP_CONTROLLER);
        }
        if (file_exists(sprintf('%sjs/%s/common.js', DOCUMENT_ROOT, APP_CONTROLLER))) {
            $this->VIEW->addJS(APP_CONTROLLER . '/common');
        }
        if (file_exists(sprintf('%sjs/%s/%s.js', DOCUMENT_ROOT, APP_CONTROLLER, APP_ACTION))) {
            $this->VIEW->addJS(APP_CONTROLLER . '/' . APP_ACTION);
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
        if (($_SESSION['xion']['login_mode'] ?? '') !== 'login') {
            $this->logout();
            if (APP_ACTION_MODE !== 'Rest') {
                $this->location(LOGOUT_URI);
            } else {
                $errorCode = 'SESSION-CLOSED';
                $errorMessage = $this->ERROR_CODE->getErrorText($errorCode);
                $return = [
                    'status'        => 'failure',
                    'errorCode'     => $errorCode,
                    'errorMessage'  => $errorMessage
                ];
                Func\Json::outputArrayToJson(
                    $return,
                    $this->OUTPUT_JSON_STYLE,
                    filter_input(INPUT_GET, 'callback'),
                    $this->SESSION_CHECK
                );
            }
        } else {
            // $this->setUserInfo($_SESSION['xion']['user_id']);
        }
    }

    /**
     * setUserInfo
     *
     * Set login user account information.
     *
     * @param string $userId User ID.
     *
     * @return void
     */
    // final protected function setUserInfo(string $userId): void
    // {
    //     $userMapper = new Database\UserMapper();
    //     $_SESSION['xion']['user_info'] = $userMapper->findByUserID($userId);
    //     $_SESSION['xion']['login_mode'] = 'login';
    // }

    /**
     * logout
     *
     * Delete the session information and log out.
     *
     * @return void
     */
    final protected function logout(): void
    {
        unset($_SESSION['xion']);
    }

    /**
     * Move URL.
     *
     * Moves to the specified URL.
     *
     * @param string  $uri  URI.
     * @param boolean $flag In service or not (true = inside service | false = outside).
     *
     * @return void
     */
    final protected function location(string $uri, bool $flag = true): void
    {
        if ($flag) {
            $uri = URI_ROOT . $uri;
        }
        header('Location: ' . $uri);
        exit();
    }

    /**
     * NotFound.
     *
     * Output 404 page.
     *
     * @return void
     */
    final protected function notFound(): void
    {
        header('HTTP/1.0 404 Not Found');
        echo file_get_contents(DIR_ROOT . '/404.html');
        exit;
    }
}
