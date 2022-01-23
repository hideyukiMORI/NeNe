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

use Nene\Database   as Database;
use Nene\Xion       as Xion;
use Nene\Xion\Log   as Log;
use Nene\Func       as Func;
use PDOStatement;
use PDO;

/**
 * Abstract class for data mapper
 * Superclass of all data mapper.
 * This class has common data mapper methods.
 *
 * @author      HideyukiMORI
 */
abstract class DataMapperBase
{
    /**
     * Database connection object
     *
     * @var PDO
     */
    protected $DB;

    /**
     * Logger
     *
     * @var Log
     */
    protected $LOGGER;

    /**
     * Class name.
     *
     * @var string
     */
    protected $CLASS;

    /**
     * Error code
     *
     * @var ErrorCode
     */
    protected $ERROR_CODE;

    protected const MODEL_CLASS = 'Nene\Xion\DataModelBase';
    protected const TARGET_TABLE = '';
    protected const KEY_SID = 'id';


    /**
     * CONSTRUCTOR
     */
    public function __construct()
    {
        $this->DB = PdoConnection::getInstance();
        $this->LOGGER = Log::getInstance();
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Database\\' . end($classPathArray);
        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addDebug('NEW : ' . $this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }



    /**
     * Get table columns.
     *
     * Returns non-primary key column names.
     *
     * @param string  $key_sid         Column name for sequence ID of auto increment.
     * @param boolean $is_exclude_date Whether to exclude the creation date and update date of the database row.
     * @param string  $className       The target class name.
     *
     * @return array Column name array.
     */
    public function getTableColumn(string $key_sid, bool $is_exclude_date = false, string $className = ''): array
    {
        $className = $className === '' ? static::MODEL_CLASS : $className;
        $DataMODEL  = str_replace('Mapper', '', $className);
        $DataObj    = new $DataMODEL();
        $column     = $DataObj->getSchema();
        if ($is_exclude_date) {
            unset($column[DB_COLUMN_NAME_CREATED]);
            unset($column[DB_COLUMN_NAME_UPDATED]);
        }
        unset($column[$key_sid]);
        return $column;
    }



    /**
     * INSERT
     *
     * @param mixed  $data      A data object or array of objects to insert into the database.
     * @param string $className The target class name.
     *
     * @return integer  Primary key sequence ID assigned by auto increment.
     */
    public function insert(mixed $data, string $className = ''): int
    {
        $targetClassName = $className === '' ? get_class($this) : $className;
        $fields = [];
        $values = [];
        $column = $this->getTableColumn(static::KEY_SID, DB_COLUMN_TIMESTAMP, $targetClassName);
        foreach ($column as $key => $var) {
            $key = preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
            $fields[] = $key;
            $values[] = ':' . $key;
        }

        $created_at     = DB_AUTO_CREATED_STAMP ? ',' . DB_COLUMN_NAME_CREATED : '';
        $created_stamp  = DB_AUTO_CREATED_STAMP ? ',NOW()' : '';
        $updated_at     = DB_AUTO_UPDATED_STAMP ? ',' . DB_COLUMN_NAME_UPDATED : '';
        $updated_stamp  = DB_AUTO_UPDATED_STAMP ? ',NOW()' : '';

        $stmt = $this->DB->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::TARGET_TABLE,
            implode(',', $fields) . $created_at . $updated_at,
            implode(',', $values) . $created_stamp . $updated_stamp
        ));
        if (!is_array($data)) {
            $data = [$data];
        }
        foreach ($data as $row) {
            if (!$row instanceof DataModelBase) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. Not an instance of the specified "' . static::MODEL_CLASS . '" class.'
                );
            } elseif (!$row->isValid()) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. The specified "' . static::MODEL_CLASS . '.' .
                        $row->validate() . '" is in violation of validation'
                );
            }
            foreach ($column as $key => $var) {
                $col = preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
                $stmt->bindValue(':' . $col, $row->$key);
            }
            $this->execute($stmt);
            $row->{static::KEY_SID} = $this->DB->lastInsertId();
        }
        return $row->{static::KEY_SID};
    }



    /**
     * UPDATE
     *
     * @param mixed $data Data object to update the database.
     *
     * @return void
     */
    public function update(mixed $data): void
    {
        $column = $this->getTableColumn(static::KEY_SID, DB_COLUMN_TIMESTAMP);
        foreach ($column as $key => $val) {
            $key = preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
            $param[] = $key . '=:' . $key;
        }
        $stmt = $this->DB->prepare(sprintf(
            'UPDATE %s SET %s WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID . ' ',
            static::TARGET_TABLE,
            implode(',', $param)
        ));
        if (!is_array($data)) {
            $data = [$data];
        }
        foreach ($data as $row) {
            if (!$row instanceof DataModelBase) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. Not an instance of the specified "' . static::MODEL_CLASS . '" class.'
                );
            } elseif (!$row->isValid()) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. The specified "' .
                        static::MODEL_CLASS . '.' . $row->isValid() . '" is in violation of validation'
                );
            }
            foreach ($column as $key => $var) {
                $col = preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
                $stmt->bindValue(':' . $col, $row->$key);
            }
            $stmt->bindValue(':' . static::KEY_SID, $row->{static::KEY_SID});
            $this->execute($stmt);
        }
    }



    /**
     * DELETE
     * To do a logical delete, use the update method or add logic to this method.
     *
     * @param mixed $data Data object to update the database.
     *
     * @return void
     */
    public function delete(mixed $data)
    {
        if (DB_IS_PHYSICAL_DELETE) {
            $stmt = $this->DB->prepare('
                DELETE FROM ' . static::TARGET_TABLE . '
                WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID . '
            ');
            if (!is_array($data)) {
                $data = [$data];
            }
            foreach ($data as $row) {
                if (!$row instanceof DataModelBase) {
                    throw new \InvalidArgumentException(
                        'DATA MAPPER ERROR. Not an instance of the specified "' .
                            static::MODEL_CLASS . '" class.'
                    );
                }
                $key_sid = $row->{static::KEY_SID};
                $stmt->bindParam(':' . static::KEY_SID, $key_sid, PDO::PARAM_INT);
                $this->execute($stmt);
            }
        }
    }



    /**
     * FIND
     * Search primary key by specified value and return one row.
     *
     * @param integer $sid Primary key value to search.
     *
     * @return mixed  Search results.
     */
    public function find(int $sid)
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE   ' . static::KEY_SID . ' =:' . static::KEY_SID . '
            LIMIT 1
        ');
        $stmt->bindParam(':' . static::KEY_SID, $sid, PDO::PARAM_INT);
        $stmt = $this->execute($stmt);
        $stmt = $this->decorate($stmt);
        return $stmt->fetch();
    }



    /**
     * Find all
     * Returns all rows from a database table.
     *
     * @param integer $limit Number of acquisitions.
     *
     * @return PDOStatement  Search results.
     */
    public function findALL(int $limit = 0): PDOStatement
    {
        $limitSQL = $limit === 0 ? '' : " LIMIT " . (int)$limit;
        $stmt = $this->executeQuery('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE 1
            ORDER BY ' . static::KEY_SID . $limitSQL . '
        ');
        return $this->decorate($stmt);
    }



    /**
     * COUNT BY ID
     * Returns whether there is a primary key row with the specified value.
     *
     * @param integer $sid Primary key value to search.
     *
     * @return integer  Search results.
     */
    public function countById(int $sid): int
    {
        $stmt = $this->DB->prepare('
            SELECT COUNT(*) FROM ' . static::TARGET_TABLE . '
            WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID . '
        ');
        $stmt->bindParam(':' . static::KEY_SID, $sid, PDO::PARAM_INT);
        return $this->execute($stmt)->fetchColumn();
    }



    /**
     * Count all
     * Returns the number of rows in a database table.
     *
     * @return integer number of rows.
     */
    public function countAll()
    {
        $stmt = $this->executeQuery('
            SELECT COUNT(*) FROM ' . static::TARGET_TABLE . '
            WHERE 1
        ');
        return $stmt->fetchColumn();
    }



    /**
     * EXECUTE
     * Try to execute stmt.
     *
     * @param PDOStatement $stmt PDOStatement you want to try.
     *
     * @return PDOStatement PDOStatement after try.
     */
    final public function execute(PDOStatement $stmt)
    {
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            echo $e->getMessage();
            exit();
        }
        return $stmt;
    }



    /**
     * EXECUTE QUERY
     * Try to query execute stmt.
     *
     * @param string $query Query statement.
     *
     * @return PDOStatement PDOStatement after try.
     */
    final public function executeQuery(string $query): PDOStatement
    {
        try {
            $stmt = $this->DB->query($query);
        } catch (\PDOException $e) {
            echo $e->getMessage();
            exit();
        }
        return $stmt;
    }



    /**
     * Get search array
     * Parse search keyword delimiter and return as array.
     *
     * @param string $searchKey Search keyword.
     *
     * @return array  Search keyword array.
     */
    public function getSearchARRAY(string $searchKey): array
    {
        $searchKey = str_replace(',', ' ', $searchKey);
        $searchKey = str_replace('、', ' ', $searchKey);
        $searchKey = str_replace('　', ' ', $searchKey);
        $searchKey = preg_replace('/\s(?=\s)/', '', $searchKey);
        $searchKey = trim($searchKey);
        $searchArray = explode(' ', $searchKey);
        return $searchArray;
    }



    /**
     * DECORATE
     * Set fetch mode to convert to the specified class.
     *
     * @param  PDOStatement $stmt PDOStatement instance.
     *
     * @return PDOStatement Instance of PDOStatement after setting.
     */
    protected function decorate(PDOStatement $stmt): PDOStatement
    {
        $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, static::MODEL_CLASS);
        return $stmt;
    }



    /**
     * ASSOCIATIVE ARRAY
     * Set fetch mode to convert to associative array.
     *
     * @param PDOStatement $stmt PDOStatement instance.
     *
     * @return PDOStatement Instance of PDOStatement after setting.
     */
    protected function assoc(PDOStatement $stmt): PDOStatement
    {
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt;
    }



    /**
     * JSON ERROR CODE
     * Output Error Json.
     *
     * @param string $errorCode    Error code.
     * @param string $errorMessage Error message.
     *
     * @return void
     */
    final protected function error(string $errorCode, string $errorMessage): void
    {
        Func\Json::outputErrorInJson($errorCode, $errorMessage);
    }
}
