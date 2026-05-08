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

use Nene\Database as Database;
use Nene\Xion\ControllerBase;

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
            return $this->API_RESPONSE->failure('LOGIN-FAILED');
        }
        $loginUser = $this->AUTH_SESSION->login($user);
        return $this->API_RESPONSE->success([
            'user' => $loginUser,
            'csrfToken' => $this->AUTH_SESSION->csrfToken(),
        ]);
    }

    /**
     * LOGOUT
     *
     * @return array<string,string> Logout response.
     */
    public function logoutPostRest(): array
    {
        $this->logout(true);
        return $this->API_RESPONSE->success();
    }
}
