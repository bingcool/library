<?php

namespace Common\Library\Tests\Db;

/**
 * @property integer $id
 * @property string user_name
 * @property int $sex
 * @property string $phone
 * @property string $birth_day
 * @property string $create_time
 * @property string $update_time
 */

class User extends ActiveRecord
{
    /**
     * @var string
     */
    protected $table = 'tbl_users';

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * User constructor.
     * @param $userId
     */
    public function __construct($userId = 0)
    {
        parent::__construct($userId);
        if($userId)
        {
            $this->loadByPk($userId);
        }
    }

    /**
     * @param $id
     * @param mixed ...$params
     * @return $this|void
     */
    public function loadByPk($id, ...$params)
    {
        $this->findOne('id=:id',[
            ':id' => $id
        ]);
        return $this;
    }

    public function onAfterInsert()
    {
        parent::onAfterInsert(); // TODO: Change the autogenerated stub
        var_dump('insertUserId='.$this->id);
    }

    /**
     * 数据库自增，这里不需要自定义返回pk
     * @return mixed|void
     */
    public function createPkValue()
    {
        return null;
    }

}