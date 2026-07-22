<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth;

use Swoolefy\Library\Oauth\Config\OauthConfig;
use Swoolefy\Library\Oauth\Contracts\OauthClientInterface;
use Swoolefy\Library\Oauth\Exception\InvalidProviderException;

/**
 * OAuth Provider 管理器（业务入口）。
 *
 * 职责：
 * - 解析 Config/oauth.php，按 provider 名创建并缓存 OauthClient
 * - 作为 swoolefy component `oauth` 的返回值，供协程/请求级 DI 单例使用
 *
 * 协程注意：
 * - Client 内部持有 accessToken / openid / state 等可变态，仅适合当前请求内复用
 * - 禁止在 Worker 进程用 static 长期缓存本 Manager 或其 Client（会串态 / CSRF state 污染）
 *
 * @see \Swoolefy\Library\Oauth\OauthFactory
 * @see docs/Oauth.md
 */
final class OauthManager
{
    /**
     * 按 provider 配置键名缓存 Client；缓存生命周期应等于 Manager（通常即一次请求）。
     *
     * @var array<string, OauthClientInterface>
     */
    private array $clients = [];

    private OauthFactory $factory;

    /**
     * @param array<string, mixed>|OauthConfig $config 完整 oauth 配置数组，或已解析的 OauthConfig
     * @param OauthFactory|null $factory 可注入工厂，便于单测替换 / 断言构造过程
     */
    public function __construct(
        private array|OauthConfig $config,
        ?OauthFactory $factory = null,
    ) {
        // 数组配置统一收敛为只读 OauthConfig，后续 provider() 只读配置不改原数组
        if (is_array($this->config)) {
            $this->config = OauthConfig::fromArray($this->config);
        }
        $this->factory = $factory ?? new OauthFactory();
    }

    /**
     * 按名称解析 OauthClient；同名首次创建后缓存在本 Manager 内。
     *
     * 登录场景必须显式传入 provider 名（无默认渠道），例如：
     * ```php
     * $client = Application::getApp()->get('oauth')->provider('weixin_oa');
     * $url = $client->getAuthUrl();
     * ```
     *
     * @param string $name 配置中 oauth_providers 的键名（如 qq / weixin_qr / wework）
     * @return OauthClientInterface 真实 SDK 门面或 FakeOauthClient
     * @throws InvalidProviderException $name 为空，或 provider 未在配置中声明
     */
    public function provider(string $name): OauthClientInterface
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidProviderException('Oauth provider name is required');
        }

        // 同请求内多次 provider('qq') 返回同一实例，便于 getAuthUrl 后读 getState()
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        if (!$this->config->hasProvider($name)) {
            throw new InvalidProviderException("Oauth provider [{$name}] is not configured");
        }

        // Factory 负责 driver 路由与凭证校验；此处只做缓存
        $client = $this->factory->make($name, $this->config->provider($name));
        $this->clients[$name] = $client;

        return $client;
    }

    /**
     * 清空已缓存 Client。
     *
     * 用途：PHPUnit tearDown；或同请求内需要「全新未换票」实例时手动调用。
     * 生产业务一般无需调用。
     */
    public function flushClients(): void
    {
        $this->clients = [];
    }

    /**
     * 返回当前持有的配置快照（调试 / 二次读取 provider 配置块）。
     */
    public function config(): OauthConfig
    {
        return $this->config;
    }
}
