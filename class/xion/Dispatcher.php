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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

use Nene\Func as Func;

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
     *
     * @return void
     */
    final public function dispatch(): void
    {
        $requestRoute = $this->resolveRequestRoute($_SERVER['REQUEST_URI'] ?? '');
        $controller = $requestRoute['controller'];
        $action = $requestRoute['action'];

        $controllerInstance = $this->getControllerInstance($controller);

        $route = $this->resolveActionRoute(
            $controllerInstance,
            $action,
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        );
        if ($route['status'] === 404) {
            header('HTTP/1.0 404 Not Found');
            echo file_get_contents(DIR_ROOT . '/404.html');
            exit;
        } elseif ($route['status'] === 405) {
            header('Allow: ' . implode(', ', $route['allowed']));
            $this->outputJsonFailure('METHOD-NOT-ALLOWED');
        } elseif ($route['status'] === 500) {
            Log::getInstance('error')->error('Route conflict detected.', [
                'controller' => $controller,
                'action' => $action,
            ]);
            $this->outputJsonFailure('ROUTE-CONFLICT');
        }
        RouteContext::getInstance()->set($controller, $action, $route['mode'], $route['method']);
        $controllerInstance->run();
    }

    /**
     * Resolve controller and action names from a request URI.
     *
     * @param string $requestUri Request URI.
     *
     * @return array{controller:string, action:string}
     */
    final public function resolveRequestRoute(string $requestUri): array
    {
        $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
        $param = trim($requestPath, '/');
        $params = [];
        if ($param != '') {
            $params = explode('/', $param);
        }
        $layersNum = defined('LAYERS_NUM') ? LAYERS_NUM : 0;
        return [
            'controller' => ($layersNum < count($params)) ? $params[$layersNum] : 'index',
            'action' => (($layersNum + 1) < count($params)) ? $params[$layersNum + 1] : 'index',
        ];
    }

    /**
     * Resolve the controller method for an action.
     *
     * REST handlers should opt into HTTP-method dispatch with names like indexGetRest.
     * The legacy indexRest convention remains supported only as a compatibility fallback.
     *
     * @param object $controllerInstance Controller instance.
     * @param string $action             Action name.
     * @param string $requestMethod      HTTP request method.
     *
     * @return array{status:int, mode:string, method:string, allowed:array<int,string>}
     */
    final public function resolveActionRoute(object $controllerInstance, string $action, string $requestMethod): array
    {
        $actionMethod = $action . 'Action';
        $legacyRestMethod = $action . 'Rest';
        $methodRestMethod = $action . $this->getRestMethodSuffix($requestMethod) . 'Rest';
        $allowedMethods = $this->getAllowedRestMethods($controllerInstance, $action);

        if (method_exists($controllerInstance, $methodRestMethod)) {
            return [
                'status' => 200,
                'mode' => 'Rest',
                'method' => $methodRestMethod,
                'allowed' => $allowedMethods,
            ];
        }
        if (
            method_exists($controllerInstance, $actionMethod)
            && method_exists($controllerInstance, $legacyRestMethod)
        ) {
            return [
                'status' => 500,
                'mode' => '',
                'method' => '',
                'allowed' => $allowedMethods,
            ];
        }
        if (method_exists($controllerInstance, $actionMethod)) {
            return [
                'status' => 200,
                'mode' => 'Action',
                'method' => $actionMethod,
                'allowed' => $allowedMethods,
            ];
        }
        if (method_exists($controllerInstance, $legacyRestMethod)) {
            return [
                'status' => 200,
                'mode' => 'Rest',
                'method' => $legacyRestMethod,
                'allowed' => $allowedMethods,
            ];
        }
        if ($allowedMethods !== []) {
            return [
                'status' => 405,
                'mode' => '',
                'method' => '',
                'allowed' => $allowedMethods,
            ];
        }
        return [
            'status' => 404,
            'mode' => '',
            'method' => '',
            'allowed' => [],
        ];
    }

    /**
     * Get the REST method suffix for an HTTP method.
     *
     * @param string $requestMethod HTTP request method.
     *
     * @return string REST method suffix.
     */
    private function getRestMethodSuffix(string $requestMethod): string
    {
        return ucfirst(strtolower($requestMethod));
    }

    /**
     * Get HTTP methods supported by method-specific REST handlers.
     *
     * @param object $controllerInstance Controller instance.
     * @param string $action             Action name.
     *
     * @return array<int,string> Supported HTTP methods.
     */
    private function getAllowedRestMethods(object $controllerInstance, string $action): array
    {
        $allowedMethods = [];
        foreach (get_class_methods($controllerInstance) as $methodName) {
            if (preg_match('/^' . preg_quote($action, '/') . '(Get|Post|Put|Patch|Delete)Rest$/', $methodName, $matches)) {
                $allowedMethods[] = strtoupper($matches[1]);
            }
        }
        sort($allowedMethods);
        return $allowedMethods;
    }

    /**
     * Determine the class file name from the controller name passed as an argument,
     * generate an instance, and return.
     *
     * @param string $controller Controller name.
     *
     * @return ControllerBase $ControllerBase Controller alias specified by argument.
     */
    private function getControllerInstance(string $controller): ControllerBase
    {
        $className = ucfirst(strtolower($controller)) . 'Controller';
        $className = '\\Nene\\Controller\\' . $className;
        if (!class_exists($className)) {
            header('HTTP/1.0 404 Not Found');
            echo file_get_contents(DIR_ROOT . '/404.html');
            exit;
        }
        $controllerInstance = new $className();
        return $controllerInstance;
    }

    /**
     * Output a catalog-backed JSON failure response and terminate dispatch.
     *
     * @param string $errorCode Catalog error code.
     *
     * @return void
     */
    private function outputJsonFailure(string $errorCode): void
    {
        Func\Json::outputArrayToJson((new ApiResponse())->failure($errorCode), 'json', '');
    }
}
