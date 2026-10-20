<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\ValueObject;

/**
 * LogGateway 已发送消息的安全快照。
 *
 * 默认号码打码、模板参数全部替换为 `***`，避免 Worker 内存长期保留 PII 与验证码明文。
 * 显式配置 `keep_phones=true` 时保留原始号码，便于本地联调断言。
 */
final class LoggedSms
{
    /**
     * @param list<string> $phones 打码后的号码（或显式保留的原始号码）
     * @param array<string, string> $params 打码后的模板参数
     */
    public function __construct(
        public array $phones,
        public string $template,
        public array $params = [],
        public ?string $signName = null,
    ) {
    }
}
