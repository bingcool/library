<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\ValueObject;

/**
 * 统一、厂商无关的发送结果。
 *
 * 设计要点：
 * - 厂商「业务态」（模板未过审 / 余额不足 / 号码无效）映射为 `ok=false` 的失败结果，而不是异常，
 *   让 `SmsVerifier` 能优雅判断；真正的调用异常才由网关抛 `SmsApiException`。
 * - `fromAliyunBody()` / `fromTencentBody()` 是**纯静态映射**，入参为已解构的数组，
 *   无需安装厂商 SDK 即可单测「成功 / 失败 / 部分失败」分支。
 * - 绝不携带 AK/SK、验证码明文。
 */
final class SendResult
{
    /**
     * @param bool $ok 是否全部成功
     * @param string $gateway 归因网关名
     * @param list<string> $successPhones 成功号码
     * @param list<array{phone: string, code?: string, reason?: string}> $failedPhones 失败明细
     * @param string|null $bizId 厂商回执 ID
     * @param string|null $requestId 厂商请求 ID
     * @param string|null $code 厂商状态码（OK / isv.xxx / Failed 等）
     * @param string|null $message 厂商描述信息
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $gateway = 'log',
        public readonly array $successPhones = [],
        public readonly array $failedPhones = [],
        public readonly ?string $bizId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * 全量成功。
     *
     * @param list<string> $phones
     */
    public static function success(
        array $phones,
        string $gateway = 'log',
        ?string $bizId = null,
        ?string $requestId = null,
        ?string $code = 'OK',
        ?string $message = null,
    ): self {
        return new self(true, $gateway, $phones, [], $bizId, $requestId, $code, $message);
    }

    /**
     * 全量 / 整体失败。
     *
     * @param list<string> $phones 失败号码
     */
    public static function failure(
        array $phones,
        string $gateway = 'log',
        ?string $code = null,
        ?string $message = null,
        ?string $requestId = null,
    ): self {
        $failed = [];
        foreach ($phones as $phone) {
            $failed[] = ['phone' => (string) $phone, 'code' => $code, 'reason' => $message];
        }

        return new self(false, $gateway, [], $failed, null, $requestId, $code, $message);
    }

    /**
     * 是否「部分成功」（有成功也有失败）。
     */
    public function isPartial(): bool
    {
        return $this->successPhones !== [] && $this->failedPhones !== [];
    }

    /**
     * 阿里云 SendSms 响应 body → SendResult。
     *
     * 期望 body 结构（已由 SDK 解构成数组）：
     * `['Code'=>'OK','Message'=>'ok','RequestId'=>'..','BizId'=>'..']`
     *
     * @param array<string, mixed> $body
     */
    public static function fromAliyunBody(array $body, PhoneList $phones, string $gateway = 'aliyun'): self
    {
        $code = isset($body['Code']) ? (string) $body['Code'] : null;
        $message = isset($body['Message']) ? (string) $body['Message'] : null;
        $requestId = isset($body['RequestId']) ? (string) $body['RequestId'] : null;
        $bizId = isset($body['BizId']) ? (string) $body['BizId'] : null;

        $all = $phones->toArray();

        if ($code === 'OK') {
            return self::success($all, $gateway, $bizId, $requestId, $code, $message);
        }

        return self::failure($all, $gateway, $code, $message, $requestId);
    }

    /**
     * 腾讯云 SendStatusSet → SendResult（逐号码判定，支持部分失败）。
     *
     * 期望每个状态项结构：
     * `['PhoneNumber'=>'+8613800008000','Code'=>'Ok','Message'=>'SUCCESS','SerialNumber'=>'..']`
     *
     * @param array<array<string, mixed>> $sendStatusSet
     * @param string|null $requestId 响应顶层 RequestId；缺省时回退到首条 SerialNumber
     * @param array<string, string> $phoneMap 实际下发的 E.164 → 业务原始号码；用于多地区回执对齐
     */
    public static function fromTencentBody(
        array $sendStatusSet,
        PhoneList $phones,
        string $gateway = 'tencent',
        ?string $requestId = null,
        array $phoneMap = [],
    ): self {
        // 建立「归一化号码 → 原始请求号码」映射。phoneMap 由网关按实际下发的 E.164 构造，
        // 是完整请求集合的权威来源；没有时退回消息里的原始号码。
        $requested = [];
        if ($phoneMap !== []) {
            foreach ($phoneMap as $sent => $original) {
                $requested[self::normalize((string) $sent)] = (string) $original;
            }
        } else {
            foreach ($phones->toArray() as $p) {
                $requested[self::normalize($p)] = $p;
            }
        }

        $success = [];
        $failed = [];
        $seenSuccess = [];
        $seenFailed = [];
        $bizId = null;
        $firstFailCode = null;
        $firstFailMsg = null;

        foreach ($sendStatusSet as $item) {
            $rawPhone = trim((string) ($item['PhoneNumber'] ?? ''));
            $code = isset($item['Code']) ? (string) $item['Code'] : null;
            $message = isset($item['Message']) ? (string) $item['Message'] : null;
            $serial = isset($item['SerialNumber']) ? (string) $item['SerialNumber'] : null;
            $bizId ??= $serial;

            // 缺号码无法归属；未请求的号码不能进入结果，统一交给缺失回执逻辑补失败
            if ($rawPhone === '') {
                continue;
            }
            $key = self::normalize($rawPhone);
            if (!isset($requested[$key])) {
                continue;
            }
            $matched = $requested[$key];

            // 腾讯云成功码为 'Ok'（大小写不敏感兜底 'SUCCESS'）
            if ($code !== null && strcasecmp($code, 'Ok') === 0) {
                if (!isset($seenSuccess[$key])) {
                    $seenSuccess[$key] = true;
                    $success[] = $matched;
                }
                continue;
            }

            if (!isset($seenFailed[$key])) {
                $seenFailed[$key] = true;
                $failed[] = ['phone' => $matched, 'code' => $code, 'reason' => $message];
                $firstFailCode ??= $code;
                $firstFailMsg ??= $message;
            }
        }

        // 厂商漏回的请求号码必须显式失败，不能静默当成发送成功
        foreach ($requested as $key => $original) {
            if (isset($seenSuccess[$key]) || isset($seenFailed[$key])) {
                continue;
            }
            $failed[] = [
                'phone' => $original,
                'code' => 'MissingStatus',
                'reason' => 'provider did not return status',
            ];
            $firstFailCode ??= 'MissingStatus';
            $firstFailMsg ??= 'provider did not return status';
        }

        $ok = $failed === [] && $success !== [];

        return new self(
            ok: $ok,
            gateway: $gateway,
            successPhones: $success,
            failedPhones: $failed,
            bizId: $bizId,
            requestId: $requestId ?? $bizId,
            code: $ok ? 'Ok' : $firstFailCode,
            message: $ok ? 'SUCCESS' : $firstFailMsg,
        );
    }

    /**
     * 号码对齐用的归一化：仅保留数字；长度 >= 11 时取末 11 位（覆盖 CN 带 +86 前缀回显）。
     *
     * 例：`13800008000` 与 `+8613800008000` 均归一为 `13800008000`。
     */
    private static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return $phone;
        }
        if (strlen($digits) >= 11) {
            return substr($digits, -11);
        }

        return $digits;
    }
}
