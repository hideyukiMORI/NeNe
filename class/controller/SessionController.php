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
        $user_id = (string)($this->REQUEST_JSON['user_id'] ?? filter_input(INPUT_POST, 'user_id') ?? '');
        $user_pass = (string)($this->REQUEST_JSON['user_pass'] ?? filter_input(INPUT_POST, 'user_pass') ?? '');
        $userMapper = new Database\UserMapper();
        $user = $userMapper->findByCredentials($user_id, $user_pass);
        if ($user === null) {
            $this->logout();
            $errorCode = 'LOGIN-FAILED';
            return ([
                'status'        => 'failure',
                'errorCode'     => $errorCode,
                'errorMessage'  => $this->ERROR_CODE->getErrorText($errorCode)
            ]);
        }
        $_SESSION['xion']['login_mode'] = 'login';
        $_SESSION['xion']['user'] = [
            'id'        => (int)$user['id'],
            'user_id'   => (string)$user['user_id'],
            'user_name' => (string)$user['user_name'],
            'e_mail'    => (string)$user['e_mail']
        ];
        return ([
            'status'    => 'success',
            'user'      => $_SESSION['xion']['user'],
            'errorCode' => ''
        ]);
    }
}
