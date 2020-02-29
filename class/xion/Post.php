<?php
namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

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
     * Get $_GET and set it to an internal variable.
     */
    protected function setValues()
    {
        foreach ($_POST as $key => $value) {
            $this->_values[$key] = $value;
        }
    }
}
