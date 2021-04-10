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

namespace Nene\Func;

/**
 * String common function
 * Please implement the character string filter etc. that you want to use for the whole project.
 */
class Text
{
    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
    }

    /**
     * getLink
     * generate and return A tag.
     *
     * @param string    $text   Link string.
     * @param string    $link   Link destination URL.
     * @param bool      $blank  Whether to open in a separate window.
     * @param string    $class  Class name.
     *
     * @return string  HTML tags generated.
     */
    final public static function getLink(string $text, string $link, bool $blank = false, string $class = ''): string
    {
        $blankHTML = $blank ? ' target="_blank" rel="noopener noreferrer"' : '';
        $linkHTML = strlen($link) > 0 ? "<a href=\"{$link}\"{$blankHTML} {$class}>{$text}</a>" : $text;
        return $linkHTML;
    }
}
