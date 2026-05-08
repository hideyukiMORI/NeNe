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

/**
 * URL Parameter class.
 * Holds parameters passed in the form of a URL.
 * RequestVariables class is inherited.
 *
 * By using this class you can get the value with a cleaner URL than GET.
 *
 * @author      HideyukiMORI
 */
class UrlParameter extends RequestVariables
{
    /**
     * setValues
     * Parse URL parameters and set it to an internal variable.
     * Separate the URL after the controller
     * and the action with / and interpret each part as "key_value".
     *
     * @return void
     */
    final protected function setValues(): void
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
        $param = trim($requestPath, '/');
        $params = [];
        if ('' != $param) {
            $params = explode('/', $param);
        }
        if (LAYERS_NUM + 2 < count($params)) {
            $urlParams = array_slice($params, LAYERS_NUM + 2);
            foreach ($urlParams as $param) {
                $split = explode('_', $param);
                if (2 == count($split)) {
                    $key = $split[0];
                    $val = $split[1];
                    $this->values[$key] = $val;
                }
            }
        }
    }
}
