<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms;

use Psr\Log\LoggerInterface;
use Swoolefy\Library\Sms\Config\SmsConfig;
use Swoolefy\Library\Sms\Contracts\SmsGatewayInterface;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Support\PhoneMask;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 短信通道管理器（业务入口 / 策略上下文）。
 *
 * 职责：
 * - 解析 `Config/sms.php`，按通道名创建并缓存 `SmsGatewayInterface`
 * - 作为 swoolefy component `sms` 的返回值，供协程 / 请求级 DI 单例使用
 *
 * 协程注意：
 * - 缓存的 Gateway 只含配置态（AK/SK/region），**不含号码 / 验证码等请求态**，可安全同请求复用
 * - 禁止在 Worker 进程用 static 长期缓存本 Manager（配置 / env 变更后不刷新）
 *
 * @see \Swoolefy\Library\Oauth\OauthManager 同款「按名解析 + 实例缓存」范式
 */
final class SmsManager
{
    /** @var array<string, SmsGatewayInterface> 通道名 → Gateway 实例 */
    private array $gateways = [];

    private SmsFactory $factory;

    /**
     * @param array<string, mixed>|SmsConfig $config 完整配置数组或已解析的 SmsConfig
     * @param LoggerInterface|null $logger 框架 log 组件；仅记录脱敏信息
     * @param SmsFactory|null $factory 可注入工厂，便于单测 / 扩展
     */
    public function __construct(
        private array|SmsConfig $config,
        private ?LoggerInterface $logger = null,
        ?SmsFactory $factory = null,
    ) {
        if (is_array($this->config)) {
            $this->config = SmsConfig::fromArray($this->config);
        }
        $this->factory = $factory ?? new SmsFactory();
    }

    /**
     * 按通道名解析 Gateway；同名首次创建后缓存在本 Manager 内。
     *
     * @param string|null $name 配置 gateways 的键名；null 用 default_gateway
     * @throws InvalidProviderException 通道未配置
     */
    public function gateway(?string $name = null): SmsGatewayInterface
    {
        $name = $name ?? $this->config->defaultGateway();
        if ($name === '') {
            throw new InvalidProviderException('Sms gateway name is required (no default configured)');
        }

        if (isset($this->gateways[$name])) {
            return $this->gateways[$name];
        }

        if (!$this->config->hasGateway($name)) {
            throw new InvalidProviderException("Sms gateway [{$name}] is not configured");
        }

        $gateway = $this->factory->make($name, $this->config->gateway($name));
        $this->gateways[$name] = $gateway;

        return $gateway;
    }

    /**
     * 用默认通道发送。
     */
    public function send(SmsMessage $message): SendResult
    {
        return $this->sendVia(null, $message);
    }

    /**
     * 指定通道发送。
     *
     * @param string|null $name 通道名；null 用 default_gateway
     */
    public function sendVia(?string $name, SmsMessage $message): SendResult
    {
        $gateway = $this->gateway($name);
        $result = $gateway->send($message);

        $this->logSend($gateway->name(), $message, $result);

        return $result;
    }

    /**
     * 注册自定义 / 扩展 gateway 驱动（透传给 Factory）。
     *
     * @param callable(array<string, mixed>, string): SmsGatewayInterface $creator
     */
    public function extendDriver(string $driver, callable $creator): void
    {
        $this->factory->extend($driver, $creator);
    }

    /**
     * 清空已缓存 Gateway。用途：PHPUnit tearDown / 需要重载配置时。
     */
    public function flushGateways(): void
    {
        $this->gateways = [];
    }

    /**
     * 当前持有的配置快照。
     */
    public function config(): SmsConfig
    {
        return $this->config;
    }

    private function logSend(string $gatewayName, SmsMessage $message, SendResult $result): void
    {
        if ($this->logger === null) {
            return;
        }

        // 安全：只记通道 / 模板 / 脱敏号码数 / 结果码，绝不记 AK/SK、验证码明文、完整号码
        $this->logger->info('sms send', [
            'gateway' => $gatewayName,
            'template' => $message->template,
            'count' => $message->phones->count(),
            'phones' => PhoneMask::maskAll($message->phones),
            'ok' => $result->ok,
            'code' => $result->code,
        ]);
    }
}
