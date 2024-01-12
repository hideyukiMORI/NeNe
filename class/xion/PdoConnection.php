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

use PDO;

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */
class PdoConnection
{
    /**
     * Instance to pass as a singleton.
     *
     * @var PdoConnection
     */
    private static $instance;

    /**
     * Database connect object
     *
     * @var PDO
     */
    public $connection;

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
        /* CHECK DATABASE TYPE */
        if (!in_array(DB_TYPE, ['MySQL', 'SQLite3'])) {
            echo('There is an error in the Database type setting. Check the configuration file.');
            exit();
        }

        /* CREATE DB OBJECT */
        try {
            switch (DB_TYPE) {
                case 'MySQL':
                    $this->connection = new PDO('mysql:host=' . DB_HOST . '; dbname=' . DB_NAME, DB_USER, DB_PASS);
                    $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                    break;
                case 'SQLite3':
                    $this->connection = new PDO('sqlite:' . DB_DIR . DB_FILE);
                    break;
            }
        } catch (\PDOException $e) {
            die('Connection failed : ' . $e->getMessage());
            exit();
        }
    }

    /**
     * DESTRUCTOR.
     */
    final public function __destruct()
    {
        $this->connection = null;
    }

    /**
     * GET INSTANCE.
     *
     * @return PDO
     */
    final public static function getInstance(): PDO
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance->connection;
    }

    /**
     * Copy inhibit.
     *
     * @return void
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
