<?php

namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * GET class
 *
 * Class that retains the contents of GET.
 * RequestVariables class is inherited.
 *
 * This class is for backward compatibility.
 * It is recommended to use filter_input for POST and GET.
 *
 * @author HideyukiMORI
 */
class QueryString extends RequestVariables
{
    /**
     * Set value
     * Get $_GET and set it to an internal variable.
     */
    final protected function setValues()
    {
        foreach ($_GET as $key => $value) {
            $this->_values[$key] = $value;
        }
    }
}
