<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * Oauth 模块异常基类。
 *
 * 业务建议：`catch (OauthException)` 覆盖配置错误、能力不支持、state、平台 API 等全部子类。
 */
class OauthException extends \RuntimeException
{
}
