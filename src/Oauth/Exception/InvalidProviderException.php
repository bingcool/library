<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * provider 未在 oauth_providers 中配置，或 driver 为空 / 未知。
 *
 * 常见触发：未传 / 空 provider 名、拼写错误、配置键已删除、Factory 收到未支持平台。
 */
final class InvalidProviderException extends OauthException
{
}
