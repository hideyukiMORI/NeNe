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

namespace Nene\Database;

use Nene\Xion\DataMapperBase as DataMapperBase;
use Nene\Database;
use PDO;

/**
 * User account data mapper.
 */
class UserMapper extends DataMapperBase
{
    const MODEL_CLASS = 'Nene\Database\User';
    const TARGET_TABLE = 'users';
    const KEY_SID = 'id';

    final public function findByUserID()
    {
        return ([]);
    }

    /**
     * CheckLogin
     * Check accounts by user ID and user pass.
     *
     * @param string $user_id       UserID
     * @param string $user_pass     UserPASS
     *
     * @return int                  User verification result.
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
        $stmt = $this->execute($stmt);
        return $stmt->fetchColumn();
    }
}
