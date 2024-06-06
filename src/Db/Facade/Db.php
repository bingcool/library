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

namespace Common\Library\Db\Facade;

use Common\Library\Db\BaseQuery;
use Common\Library\Db\PDOConnection;
use Common\Library\Db\Query;
use Common\Library\Exception\DbException;
use Swoolefy\Core\Application;
use Swoolefy\Core\Swfy;

/**
 * Class Db
 * @package Common\Library\Db\Facade
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
    public static function __callStatic($method, $args): BaseQuery
    {
        $appConf = Swfy::getAppConf();
        if (empty($appConf['default_db'])) {
            throw new DbException('app conf missing set `default_db` item');
        }
        $name = $appConf['default_db'];
        $query = self::connect($name);
        $query->{$method}(...$args);
        return $query;
    }

}