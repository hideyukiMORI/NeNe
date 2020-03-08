<?php
namespace Nene\Database;

use Nene\Xion\DataMapperBase as DataMapperBase;
use Nene\Database;
use PDO;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */


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
}
