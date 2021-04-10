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

namespace Nene\Xion;

use Nene\Model;
use Nene\Xion as Xion;
use Logger;

/**
 * Model abstract class.
 *
 * Super class of all controller.
 * Implements model common methods.
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
        $this->LOGGER = Log::getInstance('information');
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Model\\' . end($classPathArray);
        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addDebug('NEW : ' . $this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }

    /**
     * check login
     */
    final protected function checkLogin()
    {
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
     * @param string $errorCode Error code string.
     * @return string error message.
     */
    final protected function errorCode(string $errorCode): string
    {
        return $this->ERROR_CODE->getErrorText($errorCode);
    }

    /**
     * ACCESS LOG.
     */
    final protected function accessLog(string $API = '')
    {
        $user_id = $_SESSION['xion']['user_id'] ?? '';

        $log = date('[Y-m-d H:i:s]') . ' ' .
            $user_id . ' ' .
            APP_CONTROLLER . '   ' .
            APP_ACTION . '   ' .
            $API . PHP_EOL;
        file_put_contents(ACCESS_LOG_PATH, $log, FILE_APPEND);
    }
}
