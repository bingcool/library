<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Core\Application;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;

/**
 * 按 sms.php 的 redis_component 取出 Config/component/cache.php 里的 Redis 连接。
 *
 * 组件名对应 cache.php 返回数组的键（默认 cache）。
 * 取连接用 `Application::getApp()->get($name)`，返回值常是协程包装，再取 getObject()。
 */
final class RedisComponent
{
    public static function connection(string $name): RedisConnection
    {
        if ($name === '') {
            throw new InvalidProviderException('Sms redis_component is required');
        }

        if (!is_object(Application::getApp())) {
            throw new InvalidProviderException('Sms redis_component requires Application');
        }

        $component = Application::getApp()->get($name);
        if (is_object($component) && method_exists($component, 'getObject')) {
            $redis = $component->getObject();
        } else {
            $redis = $component;
        }

        if (!$redis instanceof RedisConnection) {
            throw new InvalidProviderException(sprintf(
                'Sms redis component [%s] must be a RedisConnection from Config/component/cache.php',
                $name
            ));
        }

        return $redis;
    }
}
