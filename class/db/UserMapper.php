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

namespace Nene\Database;

use Nene\Xion\DataMapperBase as DataMapperBase;
use Nene\Database;
use PDO;

/**
 * User account data mapper.
 */
class UserMapper extends DataMapperBase
{
    protected const MODEL_CLASS = 'Nene\Database\User';
    protected const TARGET_TABLE = 'users';
    protected const KEY_SID = 'id';

    /**
     * Preparing a method to get information by user ID.
     *
     * @return array
     */
    final public function findByUserID(): array
    {
        return ([]);
    }

    /**
     * CheckLogin
     * Check accounts by user ID and user pass.
     *
     * @param string $user_id   User ID.
     * @param string $user_pass User PASS.
     *
     * @return integer User verification result.
     */
    final public function checkLogin(string $user_id, string $user_pass): int
    {
        $stmt = $this->DB->prepare('
            SELECT COUNT(*) FROM ' . static::TARGET_TABLE . '
            WHERE   user_id =:user_id
            AND     user_pass =:user_pass
            LIMIT 1
        ');
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_STR);
        $stmt->bindParam(':user_pass', $user_pass, PDO::PARAM_STR);
        return (int)$this->execute($stmt)->fetchColumn();
    }
}
