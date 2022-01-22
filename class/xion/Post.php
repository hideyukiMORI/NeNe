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

namespace Nene\Xion;

/**
 * Post class.
 *
 * Class that retains the contents of POST.
 * RequestVariables class is inherited.
 *
 * This class is for backward compatibility.
 * It is recommended to use filter_input for POST and GET.
 *
 * @author      HideyukiMORI
 */
class Post extends RequestVariables
{
    /**
     * Set value
     * Get $_POST and set it to an internal variable.
     *
     * @return void
     */
    final protected function setValues(): void
    {
        foreach ($_POST as $key => $value) {
            $this->_values[$key] = $value;
        }
    }
}
