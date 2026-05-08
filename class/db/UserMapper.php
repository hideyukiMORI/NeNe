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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Database;

use Nene\Xion\DataMapperBase as DataMapperBase;
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

    /**
     * Find an active user by credentials.
     *
     * @param string $user_id   User ID.
     * @param string $user_pass User password.
     *
     * @return array<string,mixed>|null User row.
     */
    final public function findByCredentials(string $user_id, string $user_pass): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT id, user_id, user_name, e_mail
            FROM ' . static::TARGET_TABLE . '
            WHERE user_id = :user_id
            AND user_pass = :user_pass
            AND is_deleted = 0
            LIMIT 1
        ');
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_STR);
        $stmt->bindValue(':user_pass', $user_pass, PDO::PARAM_STR);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
