<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub\Exception;

/**
 * 渠道/模式参数非法，或当前没有可操作的订阅会话。
 */
final class InvalidSubscriptionException extends PubSubException
{
}
