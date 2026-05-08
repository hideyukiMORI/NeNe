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
 * Emits an HTTP response at the outer application boundary.
 */
final class HttpEmitter
{
    final public static function emit(HttpResponse $response): void
    {
        http_response_code($response->statusCode());
        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response->body();
    }
}
