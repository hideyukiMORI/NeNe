<?php
namespace Nene\Xion;

use Nene\Database   as Database;
use Nene\Xion       as Xion;
use Nene\Xion\Log   as Log;
use Nene\Func       as Func;
use \PDOStatement;
use \PDO;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * Abstract class for data mapper
 * Superclass of all data mapper.
 * This class has common data mapper methods.
 *
 * @author      HideyukiMORI
 */
abstract class DataMapperBase
{
    protected $DB;
    protected $LOGGER;
    protected $CLASS;
    protected $ERROR_CODE;

    const MODEL_CLASS = '';
    const TARGET_TABLE = '';
    const KEY_SID = '';


    /**
     * CONSTRUCTOR
     */
    public function __construct()
    {
        $this->DB = PdoConnection::getInstance();
        $this->LOGGER = Log::getInstance();
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Database\\'.end($classPathArray);

        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addInfo('NEW : '.$this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }



    /**
     * Get table columns.
     *
     * Returns non-primary key column names.
     *
     * @param string $key_sid   Column name for sequence ID of auto increment.
     * @param bool $is_exclude_date   Whether to exclude the creation date and update date of the database row.
     * @return array Column name array.
     */
    public function getTableColumn($key_sid, $is_exclude_date = false) : array
    {
        $classNAME  = get_class($this);
        $DataMODEL  = str_replace('Mapper', '', $classNAME);
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
     * @param  mixed $data  The data object you want to insert into the database.
     * @return int  Primary key sequence ID assigned by auto increment.
     */
    public function insert($data)
    {
        $modelClass = static::MODEL_CLASS;
        $fields = array();
        $values = array();
        $column = $this->getTableColumn(static::KEY_SID);
        foreach ($column as $key) {
            $key = preg_replace('/^'.DB_NUM_PREFIX.'/', '', $key);
            $fields[] = $key;
            $values[] = ':'.$key;
        }

        $created_at     = DB_AUTO_CREATED_STAMP ? ','.DB_COLUMN_NAME_CREATED : '';
        $created_stamp  = DB_AUTO_CREATED_STAMP ? ',NOW()' : '';
        $updated_at     = DB_AUTO_UPDATED_STAMP ? ','.DB_COLUMN_NAME_UPDATED : '';
        $updated_stamp  = DB_AUTO_UPDATED_STAMP ? ',NOW()' : '';

        $stmt = $this->DB->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::TARGET_TABLE,
            implode(',', $fields).$created_at.$updated_at,
            implode(',', $values).$created_stamp.$updated_stamp
        ));
        if (!is_array($data)) {
            $data = [$data];
        }
        foreach ($data as $row) {
            if (!$row instanceof $modelClass) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. Not an instance of the specified "'.$modelClass.'" class.'
                );
            } elseif (!$row->isValid()) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. The specified "'.$modelClass.'.'.
                    $row->isValid().'" is in violation of validation'
                );
            }
            foreach ($column as $key) {
                $col = preg_replace('/^'.DB_NUM_PREFIX.'/', '', $key);
                $stmt->bindValue(':'.$col, $row->$key);
            }
            $stmt = $this->execute($stmt);
            $row->{static::KEY_SID} = $this->DB->lastInsertId();
        }
        return $row->{static::KEY_SID};
    }



    /**
     * UPDATE
     *
     * @param  mixed $data  Data object to update the database.
     * @return void
     */
    public function update($data)
    {
        $modelClass = static::MODEL_CLASS;
        $column = $this->getTableColumn(static::KEY_SID);
        foreach ($column as $key => $val) {
            $key = preg_replace('/^'.DB_NUM_PREFIX.'/', '', $key);
            $param[] = $key.'=:'.$key;
        }
        $stmt = $this->DB->prepare(sprintf(
            'UPDATE %s SET %s WHERE '.static::KEY_SID.' =:'.static::KEY_SID.' ',
            static::TARGET_TABLE,
            implode(',', $param)
        ));
        if (!is_array($data)) {
            $data = [$data];
        }
        foreach ($data as $row) {
            if (!$row instanceof $modelClass) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. Not an instance of the specified "'.$modelClass.'" class.'
                );
            } elseif (!$row->isValid()) {
                throw new \InvalidArgumentException('DATA MAPPER ERROR. The specified "'.
                    $modelClass.'.'.$row->isValid().'" is in violation of validation'
                );
            }
            foreach ($column as $key) {
                $col = preg_replace('/^'.DB_NUM_PREFIX.'/', '', $key);
                $stmt->bindValue(':'.$col, $row->$key);
            }
            $stmt->bindValue(':'.static::KEY_SID, $row->{static::KEY_SID});
            $stmt = $this->execute($stmt);
        }
    }



    /**
     * DELETE
     * To do a logical delete, use the update method or add logic to this method.
     *
     * @param  mixed $data  Data object to update the database.
     * @return void
     */
    public function delete($data)
    {
        if (DB_IS_PHYSICAL_DELETE) {
            $modelClass = static::MODEL_CLASS;
            $stmt = $this->DB->prepare('
                DELETE FROM '.static::TARGET_TABLE.'
                WHERE '.static::KEY_SID.' = ?
            ');
            $stmt->bindParam(1, $key_sid, PDO::PARAM_INT);
            if (!is_array($data)) {
                $data = [$data];
            }
            foreach ($data as $row) {
                if (!$row instanceof $modelClass) {
                    throw new \InvalidArgumentException('DATA MAPPER ERROR. Not an instance of the specified "'.$modelClass.'" class.');
                }
                $key_sid = $row->{static::KEY_SID};
                $stmt = $this->execute($stmt);
            }
        }
    }



    /**
     * FIND
     * Search primary key by specified value and return one row.
     *
     * @param  int $sid  Primary key value to search.
     * @return mixed  Search results.
     */
    public function find($sid)
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM '.static::TARGET_TABLE.'
            WHERE   '.static::KEY_SID.' =:'.static::KEY_SID.'
            LIMIT 1
        ');
        $stmt->bindParam(':'.static::KEY_SID, $sid, PDO::PARAM_INT);
        $stmt = $this->execute($stmt);
        $stmt = $this->_decorate($stmt);
        return $stmt->fetch();
    }



    /**
     * Find all
     * Returns all rows from a database table.
     *
     * @return mixed  Search results.
     */
    public function findALL()
    {
        $stmt = $this->executeQuery('
            SELECT * FROM '.static::TARGET_TABLE.'
            WHERE 1
            ORDER BY '.static::KEY_SID.'
        ');
        return $this->_decorate($stmt);
    }



    /**
     * COUNT
     * Returns whether there is a primary key row with the specified value.
     *
     * @param  int $sid  Primary key value to search.
     * @return int  Search results.
     */
    public function countSID($sid)
    {
        $stmt = $this->DB->prepare('
            SELECT COUNT(*) FROM '.static::TARGET_TABLE.'
            WHERE '.static::KEY_SID.' =:'.static::KEY_SID.'
        ');
        $stmt->bindParam(':'.static::KEY_SID, $sid, PDO::PARAM_INT);
        $stmt = $this->execute($stmt);
        return $stmt->fetchColumn();
    }



    /**
     * Count all
     * Returns the number of rows in a database table.
     *
     * @return int  number of rows.
     */
    public function countALL()
    {
        $stmt = $this->executeQuery('
            SELECT COUNT(*) FROM '.static::TARGET_TABLE.'
            WHERE 1
        ');
        return $stmt->fetchColumn();
    }



    /**
     * EXECUTE
     * Try to execute stmt.
     *
     * @param   PDOStatement $stmt  PDOStatement you want to try.
     * @return  PDOStatement        PDOStatement after try.
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
     * @param   string @query       Query statement.
     * @return  PDOStatement        PDOStatement after try.
     */
    final public function executeQuery(string $query)
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
     * @param  string $searchKey  Search keyword.
     * @return array  Search keyword array.
     */
    public function getSearchARRAY(string $searchKey)
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
     * @param  \PDOStatement $stmt PDOStatement instance.
     * @return \PDOStatement  Instance of PDOStatement after setting.
     */
    protected function _decorate(\PDOStatement $stmt)
    {
        $stmt->setFetchMode(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, static::MODEL_CLASS);
        return $stmt;
    }



    /**
     * ASSOCIATIVE ARRAY
     * Set fetch mode to convert to associative array.
     *
     * @param  \PDOStatement $stmt PDOStatement instance.
     * @return \PDOStatement  Instance of PDOStatement after setting.
     */
    protected function _assoc(\PDOStatement $stmt)
    {
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt;
    }



    /**
     * JSON ERROR CODE
     * Output Error Json.
     */
    final protected function error(string $errorCode, string $errorMessage)
    {
        Func\Json::outputErrorInJson($errorCode, $errorMessage);
    }
}
