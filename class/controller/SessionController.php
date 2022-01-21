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
 * SessionController
 */
class SessionController extends ControllerBase
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
     * LOGIN
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
