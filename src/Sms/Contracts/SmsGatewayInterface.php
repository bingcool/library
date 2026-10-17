<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Contracts;

use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 短信网关策略抽象。AliyunGateway / TencentGateway / LogGateway 各为一个可插拔策略。
 *
 * 实现约定：
 * - `send()` 内部完成厂商签名、请求与响应→`SendResult` 归一；厂商「业务态失败」返回 `SendResult::failure`，
 *   仅在无法解析响应 / SDK 抛错时才抛 `SmsApiException`。
 * - 实现不得保存请求态（号码 / code / token），以便同请求内被 Manager 安全复用。
 */
interface SmsGatewayInterface
{
    /**
     * 网关名（配置 gateway 字段），用于日志与结果归因。
     */
    public function name(): string;

    /**
     * 发送短信。
     */
    public function send(SmsMessage $message): SendResult;

    /**
     * 解析模板别名 → 厂商真实模板码。未知别名（且不是 SMS_xxx / 纯数字真实码）抛 InvalidProviderException。
     *
     * @param string $alias 别名（如 login_code）或真实 TemplateCode
     */
    public function templateCode(string $alias): ?string;
}
