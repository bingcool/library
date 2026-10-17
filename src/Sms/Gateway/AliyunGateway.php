<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Gateway;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use Darabonba\OpenApi\Models\Config;
use Swoolefy\Library\Sms\Contracts\SmsGatewayInterface;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Exception\SmsApiException;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\ApiExceptionFactory;
use Swoolefy\Library\Sms\Support\ClientTimeout;
use Swoolefy\Library\Sms\Support\TemplateCodeResolver;
use Swoolefy\Library\Sms\Support\TemplateParamMapper;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 阿里云短信网关（策略）。依赖 `alibabacloud/dysmsapi-20170525`。
 *
 * 签名 / 传输细节全部委托官方 SDK；本类只做：
 * 1. 配置校验并构造 Client
 * 2. 领域消息 → SDK 请求组装
 * 3. SDK 响应 → 厂商无关 `SendResult`
 *
 * 协程：底层是 HTTP（Guzzle/curl），Swoole `SWOOLE_HOOK_ALL` 下自动协程化，`send()` 不阻塞 Worker。
 * 无请求态：client 只含 AK/SK/region，可被 Manager 在同一请求内安全复用。
 */
final class AliyunGateway implements SmsGatewayInterface
{
    /** 阿里云单次 SendSms 号码上限 */
    public const MAX_PHONES = 1000;

    /** @var object|null 官方 Dysmsapi；测试可注入替身 */
    private ?object $client;

    /** @var array<string, string> */
    private array $templates;

    private string $signName;

    private string $region;

    /**
     * @param array<string, mixed> $config access_key / access_secret / region / sign_name / templates
     * @param object|null $client 可注入预构建 client（单测 / 高级用法），注入时跳过内部构造
     * @throws InvalidProviderException 缺必要凭证
     */
    public function __construct(private array $config, ?object $client = null)
    {
        $this->templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $this->signName = (string) ($config['sign_name'] ?? '');
        $this->region = (string) ($config['region'] ?? 'cn-hangzhou');

        if ($this->signName === '') {
            throw new InvalidProviderException('Aliyun sms gateway requires sign_name');
        }

        if ($client !== null) {
            $this->client = $client;

            return;
        }

        $accessKey = (string) ($config['access_key'] ?? $config['accessKeyId'] ?? '');
        $accessSecret = (string) ($config['access_secret'] ?? $config['accessKeySecret'] ?? '');
        if ($accessKey === '' || $accessSecret === '') {
            throw new InvalidProviderException('Aliyun sms gateway requires access_key and access_secret');
        }

        $endpoint = (string) ($config['endpoint'] ?? 'dysmsapi.' . $this->region . '.aliyuncs.com');
        $timeoutMs = ClientTimeout::milliseconds($config);

        $this->client = new Dysmsapi(new Config([
            'accessKeyId' => $accessKey,
            'accessKeySecret' => $accessSecret,
            'endpoint' => $endpoint,
            'connectTimeout' => $timeoutMs,
            'readTimeout' => $timeoutMs,
        ]));
    }

    public function name(): string
    {
        return 'aliyun';
    }

    public function templateCode(string $alias): ?string
    {
        return TemplateCodeResolver::resolve($this->templates, $alias);
    }

    /**
     * 组装阿里云请求字段。
     *
     * @return array{phoneNumbers: string, signName: string, templateCode: string, templateParam: string}
     */
    public function buildRequestPayload(SmsMessage $message): array
    {
        $this->assertPhoneLimit($message);
        $templateParam = json_encode(TemplateParamMapper::toAliyun($message->params), JSON_UNESCAPED_UNICODE);
        if ($templateParam === false) {
            throw new SmsApiException('Aliyun template params are not JSON-encodable');
        }

        return [
            'phoneNumbers' => $message->phones->toString(),
            'signName' => $message->signName ?? $this->signName,
            'templateCode' => (string) $this->templateCode($message->template),
            'templateParam' => $templateParam,
        ];
    }

    /**
     * 传给 Darabonba Config 的超时（毫秒）。
     *
     * @return array{connectTimeout: int, readTimeout: int}
     */
    public function clientTimeouts(): array
    {
        $timeoutMs = ClientTimeout::milliseconds($this->config);

        return [
            'connectTimeout' => $timeoutMs,
            'readTimeout' => $timeoutMs,
        ];
    }

    public function send(SmsMessage $message): SendResult
    {
        $request = new SendSmsRequest($this->buildRequestPayload($message));

        try {
            /** @var object $response */
            $response = $this->client->sendSms($request);
        } catch (SmsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ApiExceptionFactory::fromSdk($this->name(), $e);
        }

        // 归一化为数组喂给纯映射逻辑（body 属性为小驼峰）
        $body = $response->body ?? null;
        $bodyArr = [
            'Code' => $body->code ?? ($body['Code'] ?? null),
            'Message' => $body->message ?? ($body['Message'] ?? null),
            'RequestId' => $body->requestId ?? ($body['RequestId'] ?? null),
            'BizId' => $body->bizId ?? ($body['BizId'] ?? null),
        ];

        return SendResult::fromAliyunBody($bodyArr, $message->phones, $this->name());
    }

    private function assertPhoneLimit(SmsMessage $message): void
    {
        if ($message->phones->count() > self::MAX_PHONES) {
            throw new InvalidPhoneException(sprintf(
                'Aliyun sms accepts at most %d phones per request, got %d',
                self::MAX_PHONES,
                $message->phones->count()
            ));
        }
    }
}
