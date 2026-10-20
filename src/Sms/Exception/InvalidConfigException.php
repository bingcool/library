<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * Sms 配置值非法（如不支持的 default_region）。
 *
 * 与 InvalidProviderException 区分：后者偏向网关缺失 / 凭证缺失，本类用于已提供但取值非法的配置。
 */
final class InvalidConfigException extends SmsException
{
}
