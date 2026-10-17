<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 网关未配置，或 gateway 名为空 / 未知，或缺少该网关必需凭证。
 *
 * 常见触发：未传 / 空通道名、拼写错误、配置键已删除、Factory 收到未支持的 gateway、AK/SK 缺失。
 */
final class InvalidProviderException extends SmsException
{
}
