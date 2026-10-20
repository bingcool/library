<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Gateway;

use Swoolefy\Library\Sms\Contracts\SmsGatewayInterface;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Support\PhoneMask;
use Swoolefy\Library\Sms\Support\TemplateCodeResolver;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\ValueObject\LoggedSms;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 日志 / 开发网关（策略）。不接任何厂商，直接返回可控结果；发送日志由 Manager 统一记录。
 *
 * 定位：
 * - 本地 / 联调 / 灰度环境免凭证跑通「发送 → 回执」全链路
 * - 通过 `simulate` 显式模拟厂商直接成功 / 失败 / 部分失败，让上层编排（Manager / Verifier）
 *   在无真实环境时也能被确定性验证
 *
 * 配置：
 * - `simulate`：`success`（默认）| `failure` | `partial`
 * - `fail_code` / `fail_message`：失败 / 部分失败时回填的状态码与描述
 * - `templates`：模板别名 → 真实 TemplateCode 映射（与其它网关一致）
 * - `keep_phones`：默认 false，记录快照中的号码打码；本地联调需要原始号码时显式设为 true
 *
 * 注意：作为可断言的开发网关，本类**允许**记录已发消息（`sentMessages()`）；
 * 生产厂商网关仍严格遵守「无请求态」约定，二者定位不同。
 */
final class LogGateway implements SmsGatewayInterface
{
    public const MODE_SUCCESS = 'success';

    public const MODE_FAILURE = 'failure';

    public const MODE_PARTIAL = 'partial';

    /** @var array<string, string> */
    private array $templates;

    private string $mode;

    private string $failCode;

    private ?string $failMessage;

    /** 内存里只留最近若干条，且号码/参数打码，避免 Worker 里堆积 PII 与验证码明文 */
    private const MAX_SENT = 32;

    /** @var list<LoggedSms> 已发送消息的安全快照 */
    private array $sent = [];

    /** 累计发送次数（不受快照上限影响） */
    private int $totalSends = 0;

    private bool $keepPhones;

    /**
     * @param array<string, mixed> $config simulate / fail_code / fail_message / templates / keep_phones
     */
    public function __construct(private array $config)
    {
        $this->templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $this->mode = strtolower(trim((string) ($config['simulate'] ?? self::MODE_SUCCESS)));
        if (!in_array($this->mode, [self::MODE_SUCCESS, self::MODE_FAILURE, self::MODE_PARTIAL], true)) {
            throw new InvalidProviderException(sprintf(
                'Log gateway simulate mode [%s] must be success, failure or partial',
                $this->mode
            ));
        }
        $this->failCode = (string) ($config['fail_code'] ?? 'isv.MOCK_FAILURE');
        $this->failMessage = isset($config['fail_message']) ? (string) $config['fail_message'] : 'Log gateway simulated failure';

        $keepPhones = filter_var($config['keep_phones'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($keepPhones === null) {
            throw new InvalidProviderException('Log gateway keep_phones must be a boolean');
        }
        $this->keepPhones = $keepPhones;
    }

    public function name(): string
    {
        return 'log';
    }

    public function templateCode(string $alias): ?string
    {
        return TemplateCodeResolver::resolve($this->templates, $alias);
    }

    public function send(SmsMessage $message): SendResult
    {
        $this->totalSends++;
        $this->remember($message);

        $phones = $message->phones->toArray();

        $result = match ($this->mode) {
            self::MODE_FAILURE => $this->failureResult($phones),
            self::MODE_PARTIAL => $this->partialResult($phones),
            default => $this->successResult($phones),
        };

        return $result;
    }

    /**
     * 已「发送」消息的安全快照（仅保留最近 MAX_SENT 条）。
     *
     * @return list<LoggedSms>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function lastMessage(): ?LoggedSms
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    /**
     * 累计发送次数（不受内存快照上限影响）。
     */
    public function sendCount(): int
    {
        return $this->totalSends;
    }

    /**
     * @param list<string> $phones
     */
    private function successResult(array $phones): SendResult
    {
        $req = $this->requestId();

        return SendResult::success($phones, $this->name(), $req, $req, 'OK', 'log gateway ok');
    }

    /**
     * @param list<string> $phones
     */
    private function failureResult(array $phones): SendResult
    {
        return SendResult::failure($phones, $this->name(), $this->failCode, $this->failMessage, $this->requestId());
    }

    /**
     * 部分失败：奇数位号码失败，其余成功（覆盖单串多号码、需逐号判断的场景）。
     *
     * @param list<string> $phones
     */
    private function partialResult(array $phones): SendResult
    {
        $success = [];
        $failed = [];
        foreach ($phones as $index => $phone) {
            if ($index % 2 === 1) {
                $failed[] = ['phone' => $phone, 'code' => $this->failCode, 'reason' => $this->failMessage];
            } else {
                $success[] = $phone;
            }
        }

        // 有成功也有失败视为「部分成功」（ok=false）；全失败同样 ok=false
        $ok = $success !== [] && $failed === [];

        return new SendResult(
            ok: $ok,
            gateway: $this->name(),
            successPhones: $success,
            failedPhones: $failed,
            requestId: $this->requestId(),
            code: $ok ? 'OK' : $this->failCode,
            message: $ok ? 'log gateway ok' : $this->failMessage,
        );
    }

    private function remember(SmsMessage $message): void
    {
        $redacted = [];
        foreach ($message->params as $key => $value) {
            $redacted[$key] = '***';
            unset($value);
        }

        $phones = [];
        foreach ($message->phones->toArray() as $phone) {
            $phone = (string) $phone;
            $phones[] = $this->keepPhones ? $phone : PhoneMask::mask($phone);
        }

        $this->sent[] = new LoggedSms($phones, $message->template, $redacted, $message->signName);

        if (count($this->sent) > self::MAX_SENT) {
            $this->sent = array_slice($this->sent, -self::MAX_SENT);
        }
    }

    private function requestId(): string
    {
        return 'log-' . bin2hex(random_bytes(8));
    }
}
