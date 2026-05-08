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

namespace Nene\Controller;

use Nene\Xion\ControllerBase;
use Nene\Xion\DatabaseInstaller;

/**
 * Installation and runtime health endpoint.
 */
class HealthController extends ControllerBase
{
    /**
     * The health check is public and read-only.
     *
     * @return void
     */
    protected function preAction(): void
    {
        $this->SESSION_CHECK = false;
    }

    /**
     * Check API, database, and sample schema reachability.
     *
     * @return array<string,mixed> Health response.
     */
    public function indexGetRest(): array
    {
        return $this->API_RESPONSE->success(DatabaseInstaller::health());
    }
}
