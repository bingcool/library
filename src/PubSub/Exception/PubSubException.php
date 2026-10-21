<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub\Exception;

/**
 * PubSub 模块异常基类。
 *
 * 业务侧 `catch (PubSubException)` 即可覆盖参数非法、跨协程退订、订阅态发布、发布失败等全部子类。
 */
class PubSubException extends \RuntimeException
{
}
