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
     * @var PdoConnection|null
     */
    private static ?self $instance = null;

    /**
     * Database connect object
     *
     * @var PDO|null
     */
    public $connection;

    /**
     * CONSTRUCTOR.
     */
    final private function __construct()
    {
        /* CHECK DATABASE TYPE */
        if (!in_array(DB_TYPE, ['MySQL', 'SQLite3'])) {
            throw new HttpTermination(HttpResponse::text(
                'There is an error in the Database type setting. Check the configuration file.',
                500
            ));
        }

        /* CREATE DB OBJECT */
        try {
            switch (DB_TYPE) {
                case 'MySQL':
                    $this->connection = new PDO(
                        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                        DB_USER,
                        DB_PASS
                    );
                    $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    // @phan-suppress-next-line PhanUndeclaredConstantOfClass
                    // PDO::MYSQL_ATTR_USE_BUFFERED_QUERY is a MySQL-driver constant.
                    // It is available at runtime when the MySQL PDO driver is loaded,
                    // but Phan cannot resolve driver-specific constants statically.
                    $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                    break;
                case 'SQLite3':
                    $this->connection = new PDO('sqlite:' . DB_DIR . DB_FILE);
                    $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $this->connection->exec('PRAGMA foreign_keys = ON');
                    break;
            }
        } catch (\PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new HttpTermination(HttpResponse::text(
                APP_DEBUG ? 'Connection failed : ' . $e->getMessage() : 'Internal Server Error',
                500
            ));
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
     * @return never
     */
    final public function __clone(): never
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
