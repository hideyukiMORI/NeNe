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
 * Model abstract class.
 *
 * Super class of all controller.
 * Implements model common methods.
 */
abstract class ModelBase
{
    /**
     * LOGGER
     *
     * @var Logger
     */
    protected $LOGGER;

    /**
     * Class name.
     *
     * @var string
     */
    protected $CLASS;

    /**
     * Error code
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
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->LOGGER = Log::getInstance('information');
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Model\\' . end($classPathArray);
        $routeContext = Xion\RouteContext::getInstance();
        $controller = $routeContext->controller();
        if ($controller != 'debug' && $controller != 'stub') {
            $this->LOGGER->debug('NEW : ' . $this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
        $this->AUTH_SESSION = Xion\AuthSession::getInstance();
    }

    /**
     * check login
     *
     * @return boolean
     */
    final protected function checkLogin(): bool
    {
        return $this->AUTH_SESSION->isLoggedIn();
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
     *
     * @param string $API API name.
     *
     * @return void
     */
    final protected function accessLog(string $API = '')
    {
        $log = date('[Y-m-d H:i:s]') . ' ' .
            $this->AUTH_SESSION->userIdentifier() . ' ' .
            Xion\RouteContext::getInstance()->controller() . '   ' .
            Xion\RouteContext::getInstance()->action() . '   ' .
            $API . PHP_EOL;
        file_put_contents(ACCESS_LOG_PATH, $log, FILE_APPEND);
    }
}
