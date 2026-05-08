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

use RuntimeException;

/**
 * Stops inner framework flow by carrying a response to the front controller.
 */
final class HttpTermination extends RuntimeException
{
    public function __construct(private readonly HttpResponse $response)
    {
        parent::__construct('HTTP response termination requested.');
    }

    final public function response(): HttpResponse
    {
        return $this->response;
    }
}
