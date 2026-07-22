<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * 包装第三方平台 API 错误（通常来自 Yurun\OAuthLogin\ApiException）。
 *
 * getCode() 尽量保留平台 errcode；getPrevious() 可回溯原始异常。
 * 业务可按 code 区分「用户拒绝授权 / 密钥错误 / 限流」等（需对照各平台文档）。
 */
final class OauthApiException extends OauthException
{
    /**
     * 从任意 Throwable 构造，保留 message / code / previous 链。
     *
     * 使用 (int) code：部分平台 code 为字符串数字，统一为 int 便于类型约定。
     */
    public static function fromThrowable(\Throwable $e): self
    {
        return new self($e->getMessage(), (int) $e->getCode(), $e);
    }
}
