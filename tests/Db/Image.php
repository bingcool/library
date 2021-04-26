<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\PDOConnection;

class Image extends ActiveRecord
{
    /**
     * @var string
     */
    protected $table = 'image';

    /**
     * Image constructor.
     * @param int $id
     */
    public function __construct($id = 0)
    {
        parent::__construct();
        if($id)
        {
            //$this->loadByPk();
        }
    }

    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    public function getConnection()
    {
        // TODO: Implement getConnection() method.
    }
}