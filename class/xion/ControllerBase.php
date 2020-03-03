<?php
namespace Nene\Xion;

use Nene\Model          as Model;
use Nene\Database       as Database;
use Nene\Xion           as Xion;
use Nene\Xion\Logger    as Logger;
use Nene\Func           as Func;

/**
 * AYANE
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

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
    protected $request;                     // Request
    protected $method;                      // Request method.
    protected $controller = 'index';        // Controller name.
    protected $action = 'index';            // Action name.
    protected $SESSION_CHECK = true;        // Session check flag.
    protected $LOGGER;                      // Monolog.
    protected $ERROR_CODE;                  // Error code.
    protected $REQUEST_JSON;                // Rest post.
    protected $OUTPUT_JSON_STYLE = 'json';  // Json format at rest
    protected $refController;               // Referrer controller name
    protected $refAction;                   // Referrer action name



    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->request          = new Request();
        $this->method           = $_SERVER["REQUEST_METHOD"];
        $this->LOGGER           = Log::getInstance();
        $this->ERROR_CODE       = Xion\ErrorCode::getInstance();
        $this->refController    = $_SESSION['global']['referer']['controller'] ?? '';
        $this->refAction        = $_SESSION['global']['referer']['action'] ?? '';
    }



    /**
     * run
     *
     * Controller execution.
     */
    final public function run()
    {
        if (APP_CONTROLLER != 'debug') {
            $_SESSION['global']['referer']['controller']    = APP_CONTROLLER;
            $_SESSION['global']['referer']['action']        = APP_ACTION;
            $this->LOGGER->addInfo('ACCESS : '.APP_CONTROLLER.'::'.APP_ACTION);
        }
        if (APP_ACTION_MODE == 'Rest' && $this->method == 'POST') {
            $this->REQUEST_JSON = Func\Json::inputPostJsonToArray();
        } else if (APP_ACTION_MODE == 'Action') {
            $this->setTemplate();
        }
        $this->preAction();

        if ($this->SESSION_CHECK) {
            $this->sessionCheck();
        }

        $methodName = sprintf('%s'.APP_ACTION_MODE, APP_ACTION);
        $return = $this->$methodName();

        if (APP_ACTION_MODE == 'Rest') {
            Func\Json::outputArrayToJson($return, $this->OUTPUT_JSON_STYLE, filter_input(INPUT_GET, 'callback'), $this->SESSION_CHECK);
            return $return;
        } else {
            $this->setCSS();
            $this->setJS();
            $this->VIEW->setTitle($title);
            $this->VIEW->setValue('t_siteTitle',            SITE_TITLE);
            $this->VIEW->setValue('t_copyright',            COPYRIGHT);
            $this->VIEW->setValue('t_root',                 URI_ROOT);
            $this->VIEW->setValue('t_appVersion',           VERSION);
            $this->VIEW->setValue('t_controller',           APP_CONTROLLER);
            $this->VIEW->setValue('t_action',               APP_ACTION);
            $this->VIEW->setValue('t_controller_action',    APP_CONTROLLER.'_'.APP_ACTION);
            $this->VIEW->setValue('t_debugMode',            DUBUG_MODE);
            $this->VIEW->setValue('t_login_mode',           $this->SESSION_CHECK);
            $this->VIEW->execute();
        }
    }



    /**
     * preAction
     *
     * Executed before the main process of run.
     *
     * @author  HideyukiMORI
     */
    protected function preAction()
    {
    }



    /**
     * Set output format of json
     *
     * @param   string  $style    jsonp|json
     * @return  void
     *
     */
    final protected function setOutputJsonStyle($style = 'jsonp')
    {
        $this->OUTPUT_JSON_STYLE = $style == 'jsonp' ? 'jsonp' : 'json';
    }



    /**
     * setTemplate
     *
     * Template loader.
     * The template to be used is determined from the controller name and action name and set automatically.
     */
    final protected function setTemplate()
    {
       $template = 'common';
       if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, APP_CONTROLLER)) == true) {
           $template = APP_CONTROLLER;
       }
       $templateFile = ;
       if (file_exists(sprintf('%s/%s.tpl', DIR_SMARTY_TEMPLATE, APP_CONTROLLER.'_'.APP_ACTION)) == true) {
           $template = APP_CONTROLLER.'_'.APP_ACTION;
       }
       $this->VIEW->setTemplate($template.'.tpl');
    }



    /**
     * setCSS
     *
     * Style sheet loader.
     * The style sheet to be used is determined from the controller name and action name and set automatically.
     */
    final protected function setCSS()
    {
        if (file_exists(sprintf('%scss/%s.css', DOCUMENT_ROOT, APP_CONTROLLER)) == true) {
            $this->VIEW->addCSS(APP_CONTROLLER);
        }
        if (file_exists(sprintf('%scss/%s_%s.css', DOCUMENT_ROOT, APP_CONTROLLER, APP_ACTION)$file) == true) {
            $this->VIEW->addCSS(APP_CONTROLLER.'_'.APP_ACTION);
        }
    }



    /**
     * setJS
     *
     * Javascript loader.
     * The javascript to be used is determined from the controller name and action name and automatically set.
     */
    final protected function setJS()
    {
        $file = sprintf('%sjs/%s.js', DOCUMENT_ROOT, APP_CONTROLLER);
        if (file_exists($file) == true) {
            $this->VIEW->addJS(APP_CONTROLLER);
        }
        $file = sprintf('%sjs/%s_%s.js', DOCUMENT_ROOT, APP_CONTROLLER, APP_ACTION);
        if (file_exists($file) == true) {
            $this->VIEW->addJS(APP_CONTROLLER.'_'.APP_ACTION);
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
    final protected function sessionCheck()
    {
        if(($_SESSION['xion']['login_mode'] ?? '') != 'login') {
            $this->logout();
            if (APP_ACTION_MODE != 'Rest') {
                $this->location(LOGOUT_URI);
            } else {
                $errorCode = 'SESSION-CLOSED';
                $errorMessage = $this->ERROR_CODE->getErrorText($errorCode);
                $return = [
                    'status'        => 'failure',
                    'errorCode'     => $errorCode,
                    'errorMessage'  => $errorMessage;
                Func\Json::outputArrayToJson($return, $this->OUTPUT_JSON_STYLE, filter_input(INPUT_GET, 'callback'), $this->SESSION_CHECK);
            }
        } else {
            $this->setUserInfo($_SESSION['xion']['user_id']);
        }
    }



    /**
     * setUserInfo
     *
     * Set login user account information.
     *
     * @return void
     */
    final protected function setUserInfo($userId)
    {
        $userMapper = new Database\UserMapper();
        $userInfo = $userMapper->findByUserID($userId);
        $_SESSION['xion']['user_info'] = $userInfo->toArray();
        $_SESSION['xion']['login_mode'] = 'login';
    }



    /**
     * logout
     *
     * Delete the session information and log out.
     *
     * @return void
     */
    final protected function logout()
    {
        unset($_SESSION['xion']);
    }



    /**
     * Move URL.
     *
     * Moves to the specified URL.
     *
     * @param   string  URI
     * @param   bool    In service or not (true = inside service | false = outside).
     */
    final protected function location(string $uri, bool $flag = true)
    {
        if ($flag) {
            $uri = URI_ROOT.$uri;
        }
        header('Location: '.$uri);
        exit();
    }
}