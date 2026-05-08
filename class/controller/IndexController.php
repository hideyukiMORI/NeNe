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

namespace Nene\Controller;

use Nene\Xion\ControllerBase;

/**
 * IndexController
 */
class IndexController extends ControllerBase
{
    /**
     * Processed before the controller method is executed
     *
     * @return void
     */
    protected function preAction()
    {
        $this->SESSION_CHECK = false;
    }

    /**
     * INDEX
     * Action for document root.
     *
     * @return void
     */
    public function indexAction()
    {
        $this->setTitle('NeNe - Simple Legacy PHP Framework');
        $this->VIEW->addJS('https://cdn.jsdelivr.net/npm/react@18/umd/react.production.min.js')
            ->addJS('https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.production.min.js')
            ->setString('t_contents', 'A small legacy PHP framework for URL-based applications.');
    }
}
