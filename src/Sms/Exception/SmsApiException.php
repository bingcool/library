<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Exception;

/**
 * 厂商 SDK 调用层错误：网络 / 认证 / 参数被 SDK 抛出（如 SDK 抛 TException、签名错误、请求超时）。
 *
 * 注意：厂商「业务态」返回（如模板未过审、余额不足）默认走 SendResult 失败，而不是本异常；
 * 仅当网关无法把响应解析成正常结果、或 SDK 直接抛错时才抛本异常。
 */
final class SmsApiException extends SmsException
{
    private ?string $providerCode;

    private ?string $requestId;

    /**
     * @param string $message 面向日志的简要信息，不得包含 AK/SK
     * @param int $code 异常码（沿用 \Throwable.code，可 0）
     * @param \Throwable|null $previous 原始 SDK 异常
     * @param string|null $providerCode 厂商返回的业务 code（如 isv.SMS_SIGNATURE_ILLEGAL）
     * @param string|null $requestId 厂商请求 ID，便于工单排查
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $providerCode = null,
        ?string $requestId = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->providerCode = $providerCode;
        $this->requestId = $requestId;
    }

    public function getProviderCode(): ?string
    {
        return $this->providerCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
