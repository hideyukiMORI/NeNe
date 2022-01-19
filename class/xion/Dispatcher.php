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

/**
 * Dispatches controllers and models.
 */
class Dispatcher
{

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
    }

    /**
     * Parse the controller name and action name from the URI and autoload.
     * Then generate the controller class and execute the action.
     * If there is no corresponding class, the error message string is returned.
     * If there is no corresponding class, 404 Not Found is returned.
     */
    final public function dispatch()
    {
        $param = ltrim($_SERVER['REQUEST_URI'], '/');
        $param = rtrim($param, '/');
        $params = [];
        if ($param != '') {
            $params = explode('/', $param);
        }
        $controller = (LAYERS_NUM < count($params)) ? $params[LAYERS_NUM] : 'index';
        $action = ((LAYERS_NUM + 1) < count($params)) ? $params[LAYERS_NUM + 1] : 'index';

        /* ========== SET CONTROLLER ========== */
        define('APP_CONTROLLER', $controller);
        $controllerInstance = $this->getControllerInstance($controller);
        if ($controllerInstance === null) {
            return ('Controller [' . $controller . '] is not defined.');
        }

        /* ========== SET ACTION ========== */
        define('APP_ACTION', $action);
        if (method_exists(
            $controllerInstance,
            $action . 'Action'
        ) && method_exists(
            $controllerInstance,
            $action . 'Rest'
        )) {
            echo $action . 'Action' . ' and ' . $action . 'Rest Duplicate';
            exit();
        } elseif (method_exists($controllerInstance, $action . 'Action')) {
            define('APP_ACTION_MODE', 'Action');
        } elseif (method_exists($controllerInstance, $action . 'Rest')) {
            define('APP_ACTION_MODE', 'Rest');
        } else {
            header('HTTP/1.0 404 Not Found');
            echo file_get_contents(DIR_ROOT . '/404.html');
            exit;
        }
        $controllerInstance->run();
    }

    /**
     * Determine the class file name from the controller name passed as an argument,
     * generate an instance, and return.
     *
     * @param   string  $controller Controller name.
     * @return  ControllerBase  $ControllerBase     Controller alias specified by argument.
     */
    private function getControllerInstance($controller)
    {
        $className = ucfirst(strtolower($controller)) . 'Controller';
        $className = '\\Nene\\Controller\\' . $className;
        if (false == class_exists($className)) {
            header('HTTP/1.0 404 Not Found');
            echo file_get_contents(DIR_ROOT . '/404.html');
            exit;
        }
        $controllerInstance = new $className();
        return $controllerInstance;
    }
}
