<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms;

use Swoolefy\Library\Sms\Contracts\SmsGatewayInterface;
use Swoolefy\Library\Sms\Exception\InvalidProviderException;
use Swoolefy\Library\Sms\Gateway\AliyunGateway;
use Swoolefy\Library\Sms\Gateway\LogGateway;
use Swoolefy\Library\Sms\Gateway\TencentGateway;

/**
 * 按 gateway 配置组装具体策略（SmsGatewayInterface）。
 *
 * 内置 aliyun / tencent / log。新增厂商实现 SmsGatewayInterface 后，用 extend() 注册，
 * 或在 match 中加一行。可选 SDK（未写入本库 require）在该网关内用 SdkGuard 检测。
 *
 * @see \Swoolefy\Library\Oauth\OauthFactory 同款 match(driver) 风格
 */
final class SmsFactory
{
    /**
     * 自定义驱动注册表：gateway 名 → 创建闭包（入参为配置块与通道名）。
     *
     * @var array<string, callable(array<string, mixed>, string): SmsGatewayInterface>
     */
    private array $customCreators = [];

    /**
     * 注册 / 覆盖一个 gateway 驱动。用于扩展新厂商或单测注入替身。
     *
     * @param callable(array<string, mixed>, string): SmsGatewayInterface $creator
     */
    public function extend(string $driver, callable $creator): void
    {
        $this->customCreators[$driver] = $creator;
    }

    /**
     * 根据单条 gateway 配置创建策略实例。
     *
     * @param string $name 配置键名（仅用于异常信息，便于定位通道）
     * @param array<string, mixed> $config 必须含 gateway 字段
     * @throws InvalidProviderException 缺 gateway 或未知 gateway
     */
    public function make(string $name, array $config): SmsGatewayInterface
    {
        $driver = (string) ($config['gateway'] ?? '');
        if ($driver === '') {
            throw new InvalidProviderException("Sms gateway [{$name}] missing 'gateway' field");
        }

        // 自定义优先：注册过即用（新厂商 / fake）
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($config, $name);
        }

        return match ($driver) {
            'aliyun' => new AliyunGateway($config),
            'tencent' => new TencentGateway($config),
            'log' => new LogGateway($config),
            default => throw new InvalidProviderException("Unknown sms gateway [{$driver}] for channel [{$name}]"),
        };
    }
}
