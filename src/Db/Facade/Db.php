<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db\Facade;

use Swoolefy\Library\Db\BaseQuery;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Exception\DbException;
use Swoolefy\Core\Application;
use Swoolefy\Core\Swfy;

/**
 * Class Db
 * @package Swoolefy\Library\Db\Facade
 * @mixin BaseQuery
 * @mixin Query
 */
class Db
{
    /**
     * @param string $name
     * @return BaseQuery
     */
    public static function connect(string $name): BaseQuery
    {
        /**
         * @var PDOConnection $db
         */
        $db = Application::getApp()->get($name);
        $query = new Query($db->getConnection());
        return $query;
    }

    /**
     * @param $method
     * @param $args
     * @return BaseQuery
     * @throws DbException
     */
    public static function __callStatic($method, $args)
    {
        $appConf = Swfy::getAppConf();
        if (empty($appConf['default_db'])) {
            throw new DbException('Missing set default_db item');
        }
        $name = $appConf['default_db'];
        $query = self::connect($name);
        $result = $query->{$method}(...$args);
        return $result;
    }

}