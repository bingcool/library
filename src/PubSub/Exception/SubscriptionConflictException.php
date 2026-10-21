<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub\Exception;

/**
 * 触碰订阅连接的时机/上下文不合法。
 *
 * 订阅中的连接只允许被「运行订阅循环的那个协程」读写：
 * 在其它协程里退订，或被订阅连接执行读响应命令，Swoole 会以
 * `Swoole\Error: Socket#N has already been bound to another coroutine`
 * 形式直接致命退出（业务 try/catch 兜不住），因此必须提前抛出本异常。
 */
final class SubscriptionConflictException extends PubSubException
{
}
