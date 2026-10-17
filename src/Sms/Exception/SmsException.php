<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * Sms 模块异常基类。
 *
 * 业务建议：`catch (SmsException)` 覆盖网关未配置、SDK 缺失、号码非法、厂商错误、限流等全部子类。
 *
 * @see \Swoolefy\Library\Oauth\Exception\OauthException 与 Oauth / FileStorage 保持同款基类风格
 */
class SmsException extends \RuntimeException
{
}
