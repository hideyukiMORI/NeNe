<?php

namespace Nene\Controller;

use Nene\Model as Model;
use Nene\Xion\ControllerBase;
use Nene\Database as Database;

/**
 * AYANE
 * AYANE : ayane.co.jp
 * powered by NENE.
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
    public function loginRest(): array
    {
        sleep(3);
        $user_id     = filter_input(INPUT_POST, 'user_id');
        $user_pass   = filter_input(INPUT_POST, 'user_pass');
        $userMapper = new Database\UserMapper();
        $count = $userMapper->checkLogin($user_id, $user_pass);
        if ($count == 0) {
            $errorCode = 'LOGIN-FAILED';
            return ([
                'status'        => 'failure',
                'errorCode'     => $errorCode,
                'errorMessage'  => $this->ERROR_CODE->getErrorText($errorCode)
            ]);
        }
        return ([
            'status'    => 'success',
            'user_id'   => $user_id,
            'user_pass' => $user_pass,
            'errorCode' => ''
        ]);
    }
}
