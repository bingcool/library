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

namespace Swoolefy\Library\Db\Interceptor;

use Swoolefy\Library\Db\PDOConnection;

/**
 * 租户隔离一键注册：同时注册 SQL 拦截器并绑定 Model Scope 使用的 Handler。
 */
class TenantBootstrap
{
    public static function register(TenantLineHandlerInterface $handler, bool $insertFill = true): void
    {
        PDOConnection::addGlobalSqlInterceptor(new TenantLineInterceptor($handler, $insertFill));
    }

    public static function registerForConnection(PDOConnection $connection, TenantLineHandlerInterface $handler, bool $insertFill = true): void
    {
        $connection->addSqlInterceptor(new TenantLineInterceptor($handler, $insertFill));
    }
}
