<?php

namespace Common\Library\Tests\Db;

use Common\Library\Db\PDOConnection;
use Common\Library\Db\Mysql;
use function foo\func;

class Make
{
    public static $mysqlDb;

    /**
     * @param $userId
     * @return Mysql
     */
    public static function getDbConnection($userId)
    {
        $config = [
            // 服务器地址
            'hostname' => '127.0.0.1',
            // 数据库名
            'database' => 'bingcool',
            // 用户名
            'username' => 'root',
            // 密码
            'password' => '123456',
            // 端口
            'hostport' => 3306,
            // 连接dsn
            'dsn' => '',
            // 数据库连接参数
            'params' => [],
            // 数据库编码默认采用utf8
            'charset' => 'utf8mb4',
            // 数据库表前缀
            'prefix' => '',
            // fetchType
            'fetch_type' => \PDO::FETCH_ASSOC,
            // 是否需要断线重连
            'break_reconnect' => true,
            // 是否支持事务嵌套
            'support_savepoint' => false,
            // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
            'spend_log_limit' => 30,
            // 是否开启dubug
            'debug' => 1,
            // sql 日志
            'sql_log' => __DIR__.'/sql.log',
        ];

        if (!is_object(static::$mysqlDb)) {
            $db = new Mysql($config);
            static::$mysqlDb = $db;
        }
        return static::$mysqlDb;
    }
}