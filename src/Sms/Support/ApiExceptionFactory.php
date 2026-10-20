<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Library\Sms\Exception\SmsApiException;

/**
 * 把厂商 SDK 异常转成 SmsApiException，并尽量带上厂商 code 与 requestId。
 * 不拼接配置里的 AK/SK。
 */
final class ApiExceptionFactory
{
    public static function fromSdk(string $gateway, \Throwable $e): SmsApiException
    {
        return new SmsApiException(
            ucfirst($gateway) . ' sms request failed: ' . $e->getMessage(),
            0,
            $e,
            self::providerCode($e),
            self::requestId($e),
        );
    }

    private static function providerCode(\Throwable $e): ?string
    {
        if (method_exists($e, 'getErrorCode')) {
            $code = $e->getErrorCode();
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        if (isset($e->code) && is_string($e->code) && $e->code !== '') {
            return $e->code;
        }

        // 普通 \Exception 的 code 是 protected，只能通过 getCode() 读取（整型 0 视为未设置）
        $code = $e->getCode();
        if (is_int($code) && $code !== 0) {
            return (string) $code;
        }
        if (is_string($code) && $code !== '' && $code !== '0') {
            return $code;
        }

        return null;
    }

    private static function requestId(\Throwable $e): ?string
    {
        if (method_exists($e, 'getRequestId')) {
            $requestId = $e->getRequestId();
            if (is_string($requestId) && $requestId !== '') {
                return $requestId;
            }
        }

        $data = null;
        if (method_exists($e, 'getData')) {
            $data = $e->getData();
        }
        if (!is_array($data) && isset($e->data) && is_array($e->data)) {
            $data = $e->data;
        }
        if (is_array($data)) {
            $requestId = $data['RequestId'] ?? $data['requestId'] ?? null;
            if (is_string($requestId) && $requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }
}
