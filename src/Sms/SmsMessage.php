<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms;

use Swoolefy\Library\Sms\ValueObject\PhoneList;

/**
 * 厂商无关的短信消息 DTO。
 *
 * 只承载「发给谁、用哪个模板、填什么参数、用哪个签名」，不含任何厂商字段；
 * 具体协议差异由各 Gateway + TemplateParamMapper 消化。
 *
 * 可序列化，便于投入框架 `Job` 异步发送 / 重试。
 */
final class SmsMessage
{
    public PhoneList $phones;

    private int $max;

    /**
     * @param string $template 模板别名（如 login_code）或真实 TemplateCode
     * @param array<string, mixed> $params 模板变量（关联数组）
     * @param string|null $signName 覆盖网关默认签名
     */
    public function __construct(
        string|array $phones,
        public string $template,
        public array $params = [],
        public ?string $signName = null,
        int $max = PhoneList::DEFAULT_MAX,
    ) {
        $this->max = $max;
        $this->phones = PhoneList::make($phones, $max);
    }

    /**
     * 工厂方法，语义优于 new。
     *
     * @param string|array<array-key, string|int> $phones
     * @param array<string, mixed> $params
     */
    public static function make(
        string|array $phones,
        string $template,
        array $params = [],
        ?string $signName = null,
        int $max = PhoneList::DEFAULT_MAX,
    ): self {
        return new self($phones, $template, $params, $signName, $max);
    }

    /**
     * 追加/替换收件号码（链式）。
     *
     * @param string|array<array-key, string|int> $phones
     */
    public function to(string|array $phones): self
    {
        $this->phones = PhoneList::make($phones, $this->max);

        return $this;
    }

    public function template(string $template): self
    {
        $this->template = $template;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function params(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function sign(string $signName): self
    {
        $this->signName = $signName;

        return $this;
    }
}
