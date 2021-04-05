<?php

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
    private static $instance;  // INSTANCE VARIABLE
    public $connection;        // DATABASE CONNECT OBJECT

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
        /* CHECK DATABASE TYPE */
        if (!in_array(DB_TYPE, ['MySQL', 'SQLite3'])) {
            echo ('There is an error in the Database type setting. Check the configuration file.');
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
     */
    final public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance->connection;
    }

    /**
     * Copy inhibit.
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
