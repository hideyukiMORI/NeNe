<?php
namespace Nene\Xion;

use PDO;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
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
        /* CONNECTION SETUP */
        $db_user = DB_USER; // DATABASE USER
        $db_pass = DB_PASS; // DATABASE PASSWORD
        $db_host = DB_HOST; // DATABASE HOST
        $db_name = DB_NAME; // DATABASE NAME
        /* CREATE DB OBJECT */
        try {
            $this->connection = new PDO('mysql:host='.$db_host.'; dbname='.$db_name, $db_user, $db_pass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        } catch (PDOException $e) {
            die('Connection failed : '.$e->getMessage());
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
        throw new RuntimeException('Clone is not allowed against '.get_class($this));
    }
}
