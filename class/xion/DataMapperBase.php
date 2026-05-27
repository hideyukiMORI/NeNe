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

use Monolog\Logger;
use Nene\Xion as Xion;
use PDO;
use PDOStatement;

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
    protected PDO $DB;

    /**
     * Logger
     *
     * @var Logger
     */
    protected Logger $LOGGER;

    /**
     * Class name.
     *
     * @var string
     */
    protected string $CLASS;

    /**
     * Error code
     *
     * @var ErrorCode
     */
    protected ErrorCode $ERROR_CODE;

    protected const MODEL_CLASS = 'Nene\Xion\DataModelBase';
    protected const TARGET_TABLE = '';
    protected const KEY_SID = 'id';

    /**
     * Whether this mapper uses soft-delete (logical deletion).
     *
     * When true:
     * - delete() sets deleted_at = NOW() instead of removing the row.
     * - find() / findALL() / findPage() / countAll() automatically exclude
     *   soft-deleted rows (WHERE deleted_at IS NULL).
     * - softDelete() / restore() / findTrashed() / purge() become available.
     *
     * Set to true in the concrete mapper subclass to opt in:
     *   protected const SOFT_DELETE = true;
     */
    protected const SOFT_DELETE = false;

    /**
     * CONSTRUCTOR
     */
    public function __construct()
    {
        $this->DB = PdoConnection::getInstance();
        $this->LOGGER = Log::getInstance();
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Database\\' . end($classPathArray);
        $controller = RouteContext::getInstance()->controller();
        if ($controller != 'debug' && $controller != 'stub') {
            $this->LOGGER->debug('NEW : ' . $this->CLASS);
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
     * @param string  $className       Mapper or model class name.
     *
     * @return array Column name array.
     */
    public function getTableColumn(string $key_sid, bool $is_exclude_date = false, string $className = ''): array
    {
        $modelClassName = $this->resolveModelClass($className);
        $DataObj    = new $modelClassName();
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
            $key = (string)preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
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
        $lastInsertId = 0;
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
                $col = (string)preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
                $stmt->bindValue(':' . $col, $row->$key);
            }
            $this->execute($stmt);
            $lastInsertId = (int)$this->DB->lastInsertId();
            $row->{static::KEY_SID} = $lastInsertId;
        }
        return $lastInsertId;
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
        $param = [];
        foreach ($column as $key => $val) {
            $key = (string)preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
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
                        static::MODEL_CLASS . '.' . $row->validate() . '" is in violation of validation'
                );
            }
            foreach ($column as $key => $var) {
                $col = (string)preg_replace('/^' . DB_NUM_PREFIX . '/', '', $key);
                $stmt->bindValue(':' . $col, $row->$key);
            }
            $stmt->bindValue(':' . static::KEY_SID, $row->{static::KEY_SID});
            $this->execute($stmt);
        }
    }

    /**
     * DELETE
     *
     * Physical delete when DB_IS_PHYSICAL_DELETE is true (legacy global flag).
     * Logical delete (soft delete) when SOFT_DELETE is true on this mapper:
     * sets deleted_at = NOW() instead of removing the row.
     *
     * @param mixed $data Data object(s) to delete.
     *
     * @return void
     */
    public function delete(mixed $data): void
    {
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
        }

        if (DB_IS_PHYSICAL_DELETE && !static::SOFT_DELETE) {
            $stmt = $this->DB->prepare(
                'DELETE FROM ' . static::TARGET_TABLE .
                ' WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID
            );
            foreach ($data as $row) {
                $key_sid = $row->{static::KEY_SID};
                $stmt->bindParam(':' . static::KEY_SID, $key_sid, \PDO::PARAM_INT);
                $this->execute($stmt);
            }
            return;
        }

        if (static::SOFT_DELETE) {
            $this->softDelete($data);
        }
        // When DB_IS_PHYSICAL_DELETE=false and SOFT_DELETE=false: no-op (legacy behaviour).
    }

    /**
     * FIND
     * Returns one active row by primary key.
     * Soft-deleted rows are excluded when SOFT_DELETE is true.
     *
     * @param integer $sid Primary key value to search.
     *
     * @return object|false Search result.
     */
    public function find(int $sid): object|false
    {
        $deletedFilter = static::SOFT_DELETE
            ? ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NULL'
            : '';

        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE   ' . static::KEY_SID . ' =:' . static::KEY_SID .
            $deletedFilter . '
            LIMIT 1
        ');
        $stmt->bindParam(':' . static::KEY_SID, $sid, PDO::PARAM_INT);
        $stmt = $this->execute($stmt);
        $stmt = $this->decorate($stmt);
        return $stmt->fetch();
    }

    /**
     * Find all
     * Returns all active rows.
     * Soft-deleted rows are excluded when SOFT_DELETE is true.
     *
     * @param integer $limit Number of acquisitions (0 = unlimited).
     *
     * @return PDOStatement Search results.
     */
    public function findALL(int $limit = 0): PDOStatement
    {
        $limitSQL = $limit === 0 ? '' : ' LIMIT ' . (int)$limit;
        $deletedFilter = static::SOFT_DELETE
            ? ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NULL'
            : '';
        $stmt = $this->executeQuery('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE 1' . $deletedFilter . '
            ORDER BY ' . static::KEY_SID . $limitSQL . '
        ');
        return $this->decorate($stmt);
    }

    /**
     * Cursor-based (keyset) paginated fetch.
     *
     * Returns up to $limit rows ordered by created_at DESC, id DESC.
     * Pass the previous page's next_cursor as $cursor to get the next page.
     * $limit is clamped to [1, 100].
     *
     * The query uses a (created_at, id) keyset — the table MUST have a
     * created_at column (DB_COLUMN_NAME_CREATED) and the primary key
     * defined in KEY_SID.
     *
     * @param string|null $cursor Raw base64url cursor token, or null for the first page.
     * @param int         $limit  Maximum number of items to return (clamped to 1–100).
     *
     * @return CursorPage<object> Page result with items, has_more flag, and next cursor.
     */
    public function findPage(?string $cursor, int $limit = 20): CursorPage
    {
        $limit = max(1, min(100, $limit));
        $fetch = $limit + 1;            // fetch one extra to detect has_more
        $createdCol = DB_COLUMN_NAME_CREATED;
        $idCol      = static::KEY_SID;
        $table      = static::TARGET_TABLE;

        $decoded = $cursor !== null ? Cursor::decode($cursor) : null;

        if ($decoded === null) {
            // First page — no WHERE filter
            $stmt = $this->DB->prepare(
                "SELECT * FROM {$table}
                 ORDER BY {$createdCol} DESC, {$idCol} DESC
                 LIMIT :fetch"
            );
            $stmt->bindValue(':fetch', $fetch, \PDO::PARAM_INT);
        } else {
            // Subsequent pages — keyset filter
            $stmt = $this->DB->prepare(
                "SELECT * FROM {$table}
                 WHERE ({$createdCol} < :ca OR ({$createdCol} = :ca2 AND {$idCol} < :id))
                 ORDER BY {$createdCol} DESC, {$idCol} DESC
                 LIMIT :fetch"
            );
            $stmt->bindValue(':ca', $decoded->createdAt);
            $stmt->bindValue(':ca2', $decoded->createdAt);
            $stmt->bindValue(':id', $decoded->id, \PDO::PARAM_INT);
            $stmt->bindValue(':fetch', $fetch, \PDO::PARAM_INT);
        }

        $stmt = $this->execute($stmt);
        $stmt = $this->decorate($stmt);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);   // discard the probe row
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = end($rows);
            $nextCursor = (new Cursor(
                (string)($last->{$createdCol} ?? ''),
                (int)($last->{$idCol} ?? 0)
            ))->encode();
        }

        return new CursorPage($rows, $hasMore, $nextCursor);
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
        return (int)$this->execute($stmt)->fetchColumn();
    }

    /**
     * Count all
     * Returns the number of active rows.
     * Soft-deleted rows are excluded when SOFT_DELETE is true.
     *
     * @return integer Number of rows.
     */
    public function countAll(): int
    {
        $deletedFilter = static::SOFT_DELETE
            ? ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NULL'
            : '';
        $stmt = $this->executeQuery('
            SELECT COUNT(*) FROM ' . static::TARGET_TABLE . '
            WHERE 1' . $deletedFilter . '
        ');
        return (int)$stmt->fetchColumn();
    }

    /**
     * Soft-delete one or more rows by setting deleted_at = NOW().
     *
     * Only available when SOFT_DELETE = true on the concrete mapper.
     * Called automatically by delete() when SOFT_DELETE is enabled.
     *
     * @param mixed $data Data object(s) to soft-delete.
     *
     * @return void
     */
    public function softDelete(mixed $data): void
    {
        if (!static::SOFT_DELETE) {
            return;
        }
        if (!is_array($data)) {
            $data = [$data];
        }
        $stmt = $this->DB->prepare(
            'UPDATE ' . static::TARGET_TABLE .
            ' SET ' . DB_COLUMN_NAME_DELETED . ' = NOW()' .
            ' WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID .
            ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NULL'
        );
        foreach ($data as $row) {
            if (!$row instanceof DataModelBase) {
                throw new \InvalidArgumentException(
                    'DATA MAPPER ERROR. Not an instance of the specified "' .
                        static::MODEL_CLASS . '" class.'
                );
            }
            $key_sid = $row->{static::KEY_SID};
            $stmt->bindParam(':' . static::KEY_SID, $key_sid, \PDO::PARAM_INT);
            $this->execute($stmt);
        }
    }

    /**
     * Restore a soft-deleted row by clearing deleted_at.
     *
     * Only available when SOFT_DELETE = true on the concrete mapper.
     *
     * @param integer $sid Primary key value.
     *
     * @return bool True if the row was in trash and restored; false if not found in trash.
     */
    public function restore(int $sid): bool
    {
        if (!static::SOFT_DELETE) {
            return false;
        }
        $stmt = $this->DB->prepare(
            'UPDATE ' . static::TARGET_TABLE .
            ' SET ' . DB_COLUMN_NAME_DELETED . ' = NULL' .
            ' WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID .
            ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NOT NULL'
        );
        $stmt->bindParam(':' . static::KEY_SID, $sid, \PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find soft-deleted (trashed) rows.
     *
     * Only available when SOFT_DELETE = true on the concrete mapper.
     * Returns rows WHERE deleted_at IS NOT NULL.
     *
     * @param integer $limit Number of acquisitions (0 = unlimited).
     *
     * @return PDOStatement Search results.
     */
    public function findTrashed(int $limit = 0): PDOStatement
    {
        $limitSQL = $limit === 0 ? '' : ' LIMIT ' . (int)$limit;
        $stmt = $this->executeQuery('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE ' . DB_COLUMN_NAME_DELETED . ' IS NOT NULL
            ORDER BY ' . DB_COLUMN_NAME_DELETED . ' DESC' . $limitSQL . '
        ');
        return $this->decorate($stmt);
    }

    /**
     * Permanently delete a single trashed row (must already be soft-deleted).
     *
     * Guards against purging active rows. Returns false if the row is not in trash.
     *
     * @param integer $sid Primary key value.
     *
     * @return bool True if the row was purged; false if not found in trash.
     */
    public function purge(int $sid): bool
    {
        if (!static::SOFT_DELETE) {
            return false;
        }
        $stmt = $this->DB->prepare(
            'DELETE FROM ' . static::TARGET_TABLE .
            ' WHERE ' . static::KEY_SID . ' =:' . static::KEY_SID .
            ' AND ' . DB_COLUMN_NAME_DELETED . ' IS NOT NULL'
        );
        $stmt->bindParam(':' . static::KEY_SID, $sid, \PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete all trashed rows.
     *
     * @return int Number of rows purged.
     */
    public function purgeAll(): int
    {
        if (!static::SOFT_DELETE) {
            return 0;
        }
        $stmt = $this->executeQuery(
            'DELETE FROM ' . static::TARGET_TABLE .
            ' WHERE ' . DB_COLUMN_NAME_DELETED . ' IS NOT NULL'
        );
        return $stmt->rowCount();
    }

    /**
     * EXECUTE
     * Try to execute stmt.
     *
     * @param PDOStatement $stmt PDOStatement you want to try.
     *
     * @return PDOStatement PDOStatement after try.
     */
    final public function execute(PDOStatement $stmt): PDOStatement
    {
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->handleDatabaseException($e);
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
            return $this->DB->query($query);
        } catch (\PDOException $e) {
            $this->handleDatabaseException($e);
        }
    }

    /**
     * Handle database exceptions without exposing details in production.
     *
     * @param \PDOException $exception Database exception.
     *
     * @return never
     */
    private function handleDatabaseException(\PDOException $exception): never
    {
        $this->LOGGER->error('Database query failed.', ['exception' => $exception]);
        throw new HttpTermination(HttpResponse::text(
            APP_DEBUG ? $exception->getMessage() : 'Internal Server Error',
            500
        ));
    }

    /**
     * Resolve the model class that provides schema metadata.
     *
     * @param string $className Mapper or model class name.
     *
     * @return class-string<DataModelBase> Model class name.
     */
    private function resolveModelClass(string $className = ''): string
    {
        if ($className === '') {
            return static::MODEL_CLASS;
        }
        if (is_subclass_of($className, self::class)) {
            return $className::MODEL_CLASS;
        }
        return $className;
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
        $searchKey = (string)preg_replace('/\s(?=\s)/', '', $searchKey);
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
     * @return never
     */
    final protected function error(string $errorCode, string $errorMessage): never
    {
        JsonResponder::outputError($errorCode, $errorMessage);
    }
}
