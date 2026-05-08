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
    public function loginPostRest(): array
    {
        $user_id = (string)($this->REQUEST_JSON['user_id'] ?? '');
        $user_pass = (string)($this->REQUEST_JSON['user_pass'] ?? '');
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
        $loginUser = $this->AUTH_SESSION->login($user);
        return ([
            'status'    => 'success',
            'user'      => $loginUser,
            'errorCode' => ''
        ]);
    }

    /**
     * LOGOUT
     *
     * @return array<string,string> Logout response.
     */
    public function logoutPostRest(): array
    {
        $this->logout();
        return [
            'status' => 'success',
            'errorCode' => ''
        ];
    }
}
