<?php
namespace Nene\Xion;

use Nene\Model;
use Nene\Xion as Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * Model abstract class.
 *
 * Super class of all controller.
 * Implements model common methods.
 *
 * @author HideyukiMORI
 */
abstract class ModelBase
{
    protected $LOGGER;
    protected $CLASS;
    protected $ERROR_CODE;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->LOGGER = Logger::getInstance();
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Model\\'.end($classPathArray);
        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addInfo('NEW : '.$this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }



    /**
     * check login
     */
    final protected function checkLogin() {
        $login = $_SESSION['xion']['login_mode'] ?? '';
        if ($login != 'login') {
            return false;
        }
        return true;
    }



    /**
     * ERROR CODE
     *
     * Return Error Text by Code.
     *
     * @param string $errorcode Error code string.
     * @return string error message.
     */
    final protected function errorCode(string $errorcode) : string
    {
        return $this->ERROR_CODE->getErrorText($errorcode);
    }



    /**
     * ACCESS LOG.
     */
    final protected function accessLog(string $API = '')
    {
        $user_id = $_SESSION['xion']['user_id'] ?? '';

        $log = date('[Y-m-d H:i:s]').' '.
                $user_id.' '.
                APP_CONTROLLER.'   '.
                APP_ACTION.'   '.
                $API . PHP_EOL;
        file_put_contents(ACCESS_LOG_PATH, $log, FILE_APPEND);
    }
}