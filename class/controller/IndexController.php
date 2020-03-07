<?php
namespace Nene\Controller;

use Nene\Model as Model;
use Nene\Xion\ControllerBase;
use Nene\Database as Database;

/**
 * AYANE
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * IndexController
 */
class IndexController extends ControllerBase
{
    protected function preAction()
    {
        $this->SESSION_CHECK = false;
    }



    /**
     * INDEX
     * Action for document root.
     *
     *
     * @return void
     */
    public function indexAction()
    {
        $this->setTitle('Hello NeNe-PHP!!');
        $this->VIEW->addJS('https://cdn.jsdelivr.net/npm/vue/dist/vue.js');
        $userMapper = new Database\UserMapper();
        $user = $userMapper->find(1);

        $this->VIEW->setValue('t_contents', 'Hello NeNe-PHP!!!');

        /* GINA TEST */

    }

}
