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
 * SessionController
 */
class SessionController extends ControllerBase
{
    protected function preAction()
    {
        $this->SESSION_CHECK = false;
    }



    /**
     * LOGIN
     *
     *
     * @return array
     */
    public function loginRest() : array
    {
        sleep(1);
        $user_id     = filter_input(INPUT_POST, 'user_id');
        $user_pass   = filter_input(INPUT_POST, 'user_pass');
        return ([
                'status'    => 'success',
                'user_id'   => $user_id,
                'user_pass' => $user_pass,
                'errorCode' => '']
        );
    }
}
