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

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Model as Model;
use Nene\Xion\ControllerBase;
use Nene\Database as Database;

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
        $this->setTitle('Hello NeNe-PHP!!');
        $this->VIEW->addJS('https://cdn.jsdelivr.net/npm/vue/dist/vue.js');
        // $userMapper = new Database\UserMapper();
        // $user = $userMapper->find(1);

        $this->VIEW->setString('t_contents', 'This framework is produced by AYANE International.');
    }
}
