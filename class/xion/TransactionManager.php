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
use Throwable;

/**
 * Runs database work inside a PDO transaction.
 */
class TransactionManager
{
    /**
     * Shared database connection.
     *
     * @var PDO
     */
    private $pdo;

    /**
     * CONSTRUCTOR.
     *
     * @param PDO|null $pdo Database connection. Defaults to NeNe's shared PDO.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? PdoConnection::getInstance();
    }

    /**
     * Run the callback inside a transaction.
     *
     * If an outer transaction already exists, this method does not commit or roll it back.
     * The outer boundary remains responsible for the final decision.
     *
     * @template T
     *
     * @param callable():T $callback Work to run.
     *
     * @return T Callback result.
     *
     * @throws Throwable Re-throws callback failures after rollback.
     */
    final public function run(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }
}
