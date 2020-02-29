<?php
namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * Dispatches controllers and models.
 */
class Dispatcher
{
    /**
     * Receives the controller name and action name as arguments and autoloads.
     * Then generate the controller class and execute the action.
     * If there is no corresponding class, the error message string is returned.
     */
    public function dispatch($controller, $action)
    {
        /* ========== SET CONTROLLER ========== */
        define('APP_CONTROLLER', $controller);
        $controllerInstance = $this->getControllerInstance($controller);
        if ($controllerInstance === null) {
            return('Controller ['.$controller.'] is not defined.');
        }

        /* ========== SET ACTION ========== */
        define('APP_ACTION', $action);
        if (method_exists($controllerInstance, $action.'Action')
            && method_exists($controllerInstance, $action.'Rest')) {
            echo $action.'Action'.' and '.$action.'Rest Duplicate';
            exit();
        } elseif (method_exists($controllerInstance, $action.'Action')) {
            define('APP_ACTION_MODE', 'Action');
        } elseif (method_exists($controllerInstance, $action.'Rest')) {
            define('APP_ACTION_MODE', 'Rest');
        } else {
            header('HTTP/1.0 404 Not Found');
            echo file_get_contents(DIR_ROOT.'/404.html');
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
        $className = ucfirst(strtolower($controller)).'Controller';
        $className = '\\Nene\\Controller\\'.$className;
        if (false == class_exists($className)) {
            return;
        }
        $controllerInstarnce = new $className();
        return $controllerInstarnce;
    }
}
