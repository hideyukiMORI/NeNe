<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Xion\ControllerBase;

/**
 * Server install documentation page.
 */
class ServerinstallController extends ControllerBase
{
    /**
     * The deployment guide is public documentation.
     *
     * @return void
     */
    protected function preAction(): void
    {
        $this->SESSION_CHECK = false;
    }

    /**
     * Show the traditional server installation guide.
     *
     * @return void
     */
    public function indexAction(): void
    {
        $this->setTitle('Server Install - NeNe');
        $this->VIEW->setString(
            't_contents',
            'Install NeNe on an Apache/PHP server after git clone.'
        );
    }
}
