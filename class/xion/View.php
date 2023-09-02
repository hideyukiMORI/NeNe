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

use Smarty;
use PDOStatement;

/**
 * VIEW
 * Class to manage display.
 */
class View
{
    /**
     * Instance to pass as a singleton.
     *
     * @var View
     */
    private static $instance;

    /**
     * Smarty object
     *
     * @var Smarty
     */
    public $smarty;

    /**
     * Template file name.
     *
     * @var string
     */
    private $template;

    /**
     * CSS file name array
     *
     * @var array
     */
    private $cssArray = [];

    /**
     * Javascript file name array
     *
     * @var array
     */
    private $jsArray  = [];

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
        $this->smarty = new Smarty();                           // SMARTY OBJECT
        $this->smarty->template_dir  = DIR_SMARTY_TEMPLATE;     // TEMPLATE DIR
        $this->smarty->compile_dir   = DIR_SMARTY_COMPILE;      // TEMPLATE COMPILE DIR
        $this->smarty->config_dir    = DIR_SMARTY_CONFIG;       // CONFIG DIR
        $this->smarty->addPluginsDir(DIR_SMARTY_PLUGINS);       // PLUGINS DIR
        $this->smarty->escape_html   = true;
    }

    /**
     * Get instance.
     * Returns the display management singleton class.
     *
     * @return View Singleton class for display management.
     */
    final public static function getInstance(): View
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            self::$instance
                ->addCSS('common')
                ->addCSS('components/common')
                ->addJS('common')
                ->setString('t_contents', '');
        }
        return self::$instance;
    }

    /**
     * Set template
     * Set the template file.
     *
     * @param string $p_template Template file name.
     *
     * @return View
     */
    final public function setTemplate(string $p_template): View
    {
        self::$instance->template = $p_template;
        return self::$instance;
    }

    /**
     * Set title
     * Set the title name of the page.
     *
     * @param string $p_title Page title name.
     *
     * @return View
     */
    final public function setTitle(string $p_title): View
    {
        self::$instance->smarty->assign('t_title', SITE_TITLE_PRE . $p_title . SITE_TITLE_SUFFIX);
        return self::$instance;
    }

    /**
     * Add css
     * Add the style sheet to use.
     * If the passed argument is a URL, register that URL. Otherwise, register the file in the CSS directory.
     *
     * @param string $p_css Style sheet file name.
     * @return View
     */
    final public function addCSS(string $p_css): View
    {
        if (strlen($p_css) > 0) {
            if (filter_var($p_css, FILTER_VALIDATE_URL)) {
                self::$instance->cssArray[] = $p_css;
            } else {
                self::$instance->cssArray[] = URI_CSS . $p_css . '.css';
            }
        }
        return self::$instance;
    }

    /**
     * Set css
     * Set the style sheet to be used in the view.
     *
     * @return View
     */
    final public function setCSS(): View
    {
        $cssArray = [];
        foreach (self::$instance->cssArray as $fileName) {
            if (filter_var($fileName, FILTER_VALIDATE_URL)) {
                $cssArray[] = $fileName;
            } elseif (file_exists(DOCUMENT_ROOT . $fileName)) {
                $fileTime = filemtime(DOCUMENT_ROOT . $fileName);
                $cssArray[] = $fileName . '?' . $fileTime;
            }
        }
        self::$instance->setValues('t_css', $cssArray);
        return self::$instance;
    }

    /**
     * Add javascript
     * Add javascript to be used.
     *
     * @param string $fileName Javascript file name.
     *
     * @return View
     */
    final public function addJS(string $fileName): View
    {
        if (strlen($fileName) > 0) {
            if (filter_var($fileName, FILTER_VALIDATE_URL)) {
                self::$instance->jsArray[] = $fileName;
            } else {
                self::$instance->jsArray[] = URI_JS . $fileName . '.js';
            }
        }
        return self::$instance;
    }

    /**
     * Set javascript
     * Set the javascript to be used in the view.
     *
     * @return View
     */
    final public function setJS(): View
    {
        $jsArray = [];
        foreach (self::$instance->jsArray as $filename) {
            if (filter_var($filename, FILTER_VALIDATE_URL)) {
                $jsArray[] = $filename;
            } elseif (file_exists(DOCUMENT_ROOT . $filename)) {
                $fileTime = filemtime(DOCUMENT_ROOT . $filename);
                $jsArray[] = $filename . '?' . $fileTime;
            }
        }
        self::$instance->setValues('t_js', $jsArray);
        return self::$instance;
    }

    /**
     * Set string.
     * Set the string in the template.
     *
     * @param string $p_target Target variable name in template file.
     * @param string $p_value  The string to set.
     *
     * @return View
     */
    final public function setString(string $p_target, string $p_value): View
    {
        self::$instance->smarty->assign($p_target, $p_value);
        return self::$instance;
    }

    /**
     * Set integer.
     * Set the integer in the template.
     *
     * @param string  $p_target Target variable name in template file.
     * @param integer $p_value  The integer to set.
     *
     * @return View
     */
    final public function setInteger(string $p_target, int $p_value): View
    {
        self::$instance->smarty->assign($p_target, $p_value);
        return self::$instance;
    }

    /**
     * Set value.
     * Set the value in the template.
     *
     * @param string $p_target Target variable name in template file.
     * @param mixed  $p_value  The value to set.
     *
     * @return View
     */
    // final public function setValue(string $p_target, mixed $p_value): View
    // {
    //     self::$instance->smarty->assign($p_target, $p_value);
    //     return self::$instance;
    // }

    /**
     * Set values.
     * Set the array in the template.
     *
     * @param string $p_target Target variable name in template file.
     * @param array  $p_value  The array to set.
     *
     * @return View
     */
    final public function setValues(string $p_target, array $p_value): View
    {
        self::$instance->smarty->assign($p_target, $p_value);
        return self::$instance;
    }

    /**
     * Set PDOStatement.
     *
     * @param string       $p_target Target variable name in template file.
     * @param PDOStatement $p_value  The PDOStatement to set.
     *
     * @return View
     */
    final public function setPDOStatement(string $p_target, PDOStatement $p_value): View
    {
        self::$instance->smarty->assign($p_target, $p_value);
        return self::$instance;
    }

    /**
     * Set data model object.
     *
     * @param string        $p_target Target variable name in template file.
     * @param DataModelBase $p_value  The data model to set.
     *
     * @return View
     */
    final public function setObject(string $p_target, DataModelBase $p_value): View
    {
        self::$instance->smarty->assign($p_target, $p_value);
        return self::$instance;
    }

    /**
     * Set data object.
     *
     * @param array $dataArray Pass the data to vue.js.
     *
     * @return View
     */
    final public function setDataObject(array $dataArray): View
    {
        self::$instance->smarty->assign(
            't_dataObject',
            '<script type="text/javascript">const dataObject = ' . json_encode($dataArray) . '</script>'
        );
        return self::$instance;
    }

    /**
     * Execute Display
     * Output page.
     *
     * @return void
     */
    final public function execute()
    {
        self::$instance
            ->setCSS()
            ->setJS()
            ->smarty->display(self::$instance->smarty->template_dir[0] . self::$instance->template);
    }

    /**
     * Display error
     * Output error page.
     *
     * @param string $p_message Error message text.
     *
     * @return void
     */
    final public function error(string $p_message): void
    {
        self::$instance
            ->setString('t_root', URI_ROOT)
            ->setString('t_controller', APP_CONTROLLER)
            ->setString('t_controller_action', APP_CONTROLLER . '_' . APP_ACTION)
            ->setTitle('ERROR')
            ->setString('t_message', $p_message)
            ->setTemplate('error.tpl')
            ->execute();
        exit;
    }

    /**
     * Copy inhibit.
     *
     * @throws RuntimeException If you try to duplicate it, it will throw an exception because it is a singleton.
     *
     * @return  void
     */
    final public function __clone(): void
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
