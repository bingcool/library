<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\PDOConnection;

class ActiveRecord extends \Common\Library\Db\Model
{
    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    public function getConnection()
    {
        return $db ?? null;
    }
}