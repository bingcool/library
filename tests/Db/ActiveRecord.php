<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\PDOConnection;

class ActiveRecord extends \Common\Library\Db\Model
{
    /**
     * @var
     */
    public $userId;

    public function __construct($userId)
    {
        $this->userId;
        parent::__construct($userId);
    }

    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    public function getConnection()
    {

    }
}