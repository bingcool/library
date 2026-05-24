<?php

namespace Swoolefy\Library\Tests\Db;

use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Db\PDOConnection;

class ActiveRecord extends \Swoolefy\Library\Db\Model
{
    /**
     * @var
     */
    public $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
        parent::__construct($userId);
    }

    /**
     * 获取当前模型的数据库
     * @return Mysql
     */
    public function getConnection()
    {
        return Make::getDbConnection($this->userId);
    }

    /**
     * @return mixed|void
     */
    public function createPkValue()
    {
        return time() + rand(1, 100000);
    }
}