<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Testing;

use Swoolefy\Library\Sms\Contracts\SmsGatewayInterface;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 可编程假网关：按脚本队列返回成功 / 失败结果，并记录收到的消息。
 *
 * 用途：在「真实环境」之外模拟厂商直接发送成功、失败、部分失败，
 * 让 Manager / SmsVerifier 的编排分支可被确定性单测覆盖。
 *
 * 支持：
 * - `queue(SendResult ...)`：按调用顺序弹出预置结果
 * - `responder(callable)`：每条消息动态决定结果
 * - 队列耗尽后回退到 `defaultResult`
 */
final class FakeSmsGateway implements SmsGatewayInterface
{
    /** @var list<SmsMessage> */
    private array $sent = [];

    /** @var list<SendResult> */
    private array $queue = [];

    /** @var (callable(SmsMessage): SendResult)|null */
    private $responder = null;

    /** @var array<string, string> */
    private array $templates;

    public function __construct(
        private string $gatewayName = 'fake',
        array $templates = [],
        private ?SendResult $defaultResult = null,
    ) {
        $this->templates = $templates;
        $this->defaultResult ??= SendResult::success([], $this->gatewayName);
    }

    public function name(): string
    {
        return $this->gatewayName;
    }

    /**
     * 追加脚本结果队列。
     */
    public function queue(SendResult ...$results): self
    {
        foreach ($results as $r) {
            $this->queue[] = $r;
        }

        return $this;
    }

    /**
     * 设置动态响应回调。
     *
     * @param callable(SmsMessage): SendResult $responder
     */
    public function responder(callable $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function send(SmsMessage $message): SendResult
    {
        $this->sent[] = $message;

        if ($this->responder !== null) {
            return ($this->responder)($message);
        }

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        // 默认结果回填本条号码，便于断言 ok/successPhones
        return $this->defaultResult->ok
            ? SendResult::success($message->phones->toArray(), $this->gatewayName)
            : SendResult::failure($message->phones->toArray(), $this->gatewayName, $this->defaultResult->code, $this->defaultResult->message);
    }

    public function templateCode(string $alias): ?string
    {
        return isset($this->templates[$alias]) ? (string) $this->templates[$alias] : $alias;
    }

    /**
     * @return list<SmsMessage>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function lastMessage(): ?SmsMessage
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function sendCount(): int
    {
        return count($this->sent);
    }
}
