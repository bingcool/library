<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Gateway;

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
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use TencentCloud\Sms\V20210111\SmsClient;

/**
 * 腾讯云短信网关（策略）。依赖 `tencentcloud/sms`。
 *
 * 与 AliyunGateway 的差异全部封在本类内（对业务无感）：
 * - 号码需 E.164（带 +），CN 号码补 `+86`
 * - `TemplateParamSet` 是**字符串列表**（按占位顺序），由 TemplateParamMapper::toTencent 生成
 * - 响应为 `SendStatusSet`，逐号码判定，支持部分成功
 *
 * 协程：底层 HTTP，Swoole hook 下自动协程化；client 无请求态，可被 Manager 复用。
 */
final class TencentGateway implements SmsGatewayInterface
{
    /** 腾讯云单次 SendSms 号码上限 */
    public const MAX_PHONES = 200;

    /** @var object|null 官方 SmsClient；测试可注入替身 */
    private ?object $client;

    /** @var array<string, string> */
    private array $templates;

    private string $signName;

    private string $sdkAppId;

    private string $region;

    private string $countryCode;

    /**
     * @param array<string, mixed> $config secret_id / secret_key / sdk_app_id / region / sign_name / templates / default_country_code
     * @param object|null $client 可注入预构建 client（单测 / mock）
     * @throws InvalidProviderException 缺必要凭证
     */
    public function __construct(private array $config, ?object $client = null)
    {
        $this->templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $this->signName = (string) ($config['sign_name'] ?? '');
        $this->sdkAppId = (string) ($config['sdk_app_id'] ?? $config['sms_app_id'] ?? '');
        $this->region = (string) ($config['region'] ?? 'ap-guangzhou');
        $this->countryCode = (string) ($config['default_country_code'] ?? '86');

        if ($this->signName === '') {
            throw new InvalidProviderException('Tencent sms gateway requires sign_name');
        }

        if ($client !== null) {
            $this->client = $client;

            return;
        }

        $secretId = (string) ($config['secret_id'] ?? $config['access_key'] ?? '');
        $secretKey = (string) ($config['secret_key'] ?? $config['access_secret'] ?? '');
        if ($secretId === '' || $secretKey === '') {
            throw new InvalidProviderException('Tencent sms gateway requires secret_id and secret_key');
        }
        if ($this->sdkAppId === '') {
            throw new InvalidProviderException('Tencent sms gateway requires sdk_app_id');
        }

        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint((string) ($config['endpoint'] ?? 'sms.tencentcloudapi.com'));
        $httpProfile->setReqTimeout(ClientTimeout::seconds($config));
        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);

        $this->client = new SmsClient(new Credential($secretId, $secretKey), $this->region, $clientProfile);
    }

    public function name(): string
    {
        return 'tencent';
    }

    public function templateCode(string $alias): ?string
    {
        return TemplateCodeResolver::resolve($this->templates, $alias);
    }

    /**
     * 腾讯云请求字段。模板 ID 必须是 TemplateId，没有 TemplateCode。
     *
     * @return array{PhoneNumberSet: list<string>, SmsSdkAppId: string, SignName: string, TemplateId: string, TemplateParamSet: list<string>}
     */
    public function buildRequestPayload(SmsMessage $message): array
    {
        $this->assertPhoneLimit($message);

        return [
            'PhoneNumberSet' => $this->toE164($message->phones->toArray()),
            'SmsSdkAppId' => $this->sdkAppId,
            'SignName' => $message->signName ?? $this->signName,
            'TemplateId' => (string) $this->templateCode($message->template),
            'TemplateParamSet' => TemplateParamMapper::toTencent($message->params),
        ];
    }

    public function clientTimeoutSeconds(): int
    {
        return ClientTimeout::seconds($this->config);
    }

    public function send(SmsMessage $message): SendResult
    {
        $payload = $this->buildRequestPayload($message);
        $request = new SendSmsRequest();
        foreach ($payload as $field => $value) {
            $request->{$field} = $value;
        }

        try {
            /** @var object $response */
            $response = $this->client->SendSms($request);
        } catch (SmsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ApiExceptionFactory::fromSdk($this->name(), $e);
        }

        $statusSet = $response->SendStatusSet ?? [];
        $rows = [];
        foreach ($statusSet as $status) {
            $rows[] = [
                'PhoneNumber' => $status->PhoneNumber ?? ($status['PhoneNumber'] ?? ''),
                'Code' => $status->Code ?? ($status['Code'] ?? null),
                'Message' => $status->Message ?? ($status['Message'] ?? null),
                'SerialNumber' => $status->SerialNumber ?? ($status['SerialNumber'] ?? null),
            ];
        }

        $requestId = isset($response->RequestId) ? (string) $response->RequestId : null;

        return SendResult::fromTencentBody($rows, $message->phones, $this->name(), $requestId);
    }

    /**
     * 号码补 E.164 前缀：无 `+` 的按 default_country_code 补（CN → +86）。
     *
     * @param list<string> $phones
     * @return list<string>
     */
    private function toE164(array $phones): array
    {
        $out = [];
        foreach ($phones as $phone) {
            if (str_starts_with($phone, '+')) {
                $out[] = $phone;
            } else {
                $out[] = '+' . $this->countryCode . $phone;
            }
        }

        return $out;
    }

    private function assertPhoneLimit(SmsMessage $message): void
    {
        if ($message->phones->count() > self::MAX_PHONES) {
            throw new InvalidPhoneException(sprintf(
                'Tencent sms accepts at most %d phones per request, got %d',
                self::MAX_PHONES,
                $message->phones->count()
            ));
        }
    }
}
