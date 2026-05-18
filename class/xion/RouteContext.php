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

/**
 * Holds the current dispatched route.
 */
class RouteContext
{
    /**
     * Instance to pass as a singleton.
     *
     * @var RouteContext
     */
    private static $instance;

    /**
     * Controller name.
     *
     * @var string
     */
    private string $controller = 'index';

    /**
     * Action name.
     *
     * @var string
     */
    private string $action = 'index';

    /**
     * Action mode.
     *
     * @var string
     */
    private string $mode = 'Action';

    /**
     * Controller method name.
     *
     * @var string
     */
    private string $method = 'indexAction';

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
    }

    /**
     * GET INSTANCE.
     *
     * @return RouteContext
     */
    final public static function getInstance(): RouteContext
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set current route information.
     *
     * @param string $controller Controller name.
     * @param string $action     Action name.
     * @param string $mode       Action mode.
     * @param string $method     Controller method name.
     *
     * @return void
     */
    final public function set(string $controller, string $action, string $mode, string $method): void
    {
        $this->controller = $controller;
        $this->action = $action;
        $this->mode = $mode;
        $this->method = $method;
    }

    /**
     * Reset current route information to framework defaults.
     *
     * @return void
     */
    final public function reset(): void
    {
        $this->controller = 'index';
        $this->action = 'index';
        $this->mode = 'Action';
        $this->method = 'indexAction';
    }

    /**
     * Get controller name.
     *
     * @return string Controller name.
     */
    final public function controller(): string
    {
        return $this->controller;
    }

    /**
     * Get action name.
     *
     * @return string Action name.
     */
    final public function action(): string
    {
        return $this->action;
    }

    /**
     * Get action mode.
     *
     * @return string Action mode.
     */
    final public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Get controller method name.
     *
     * @return string Controller method name.
     */
    final public function method(): string
    {
        return $this->method;
    }

    /**
     * Check whether current action mode is REST.
     *
     * @return boolean REST mode flag.
     */
    final public function isRest(): bool
    {
        return $this->mode === 'Rest';
    }

    /**
     * Check whether current action mode is server-rendered action.
     *
     * @return boolean Action mode flag.
     */
    final public function isAction(): bool
    {
        return $this->mode === 'Action';
    }

    /**
     * Copy inhibit.
     *
     * @return never
     */
    final public function __clone(): never
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
