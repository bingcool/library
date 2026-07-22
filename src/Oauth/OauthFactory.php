<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth;

use Swoolefy\Library\Oauth\Contracts\OauthClientInterface;
use Swoolefy\Library\Oauth\Exception\InvalidProviderException;
use Swoolefy\Library\Oauth\Exception\OauthConfigException;
use Swoolefy\Library\Oauth\Support\OpenidModeMapper;
use Swoolefy\Library\Oauth\Testing\FakeOauthClient;
use Yurun\OAuthLogin\Alipay\OAuth2 as AlipayOAuth2;
use Yurun\OAuthLogin\Base;
use Yurun\OAuthLogin\DingTalk\OAuth2 as DingTalkOAuth2;
use Yurun\OAuthLogin\FeiShu\OAuth2 as FeiShuOAuth2;
use Yurun\OAuthLogin\QQ\OAuth2 as QQOAuth2;
use Yurun\OAuthLogin\Weixin\OAuth2 as WeixinOAuth2;
use Yurun\OAuthLogin\WeWork\OAuth2 as WeWorkOAuth2;

/**
 * 按 provider 配置的 driver 组装 OauthClient（或 Fake）。
 *
 * 职责边界：
 * - 校验必要凭证（app_id / secret / callback / 支付宝私钥 / 企微 agent_id）
 * - new 对应 Yurun OAuth2，并写入 scope、loginAgentUrl、openidMode 等可选字段
 * - 不发起任何网络请求（构造阶段可单测）
 *
 * 微信三类驱动共用 Weixin\OAuth2，差异由 OauthClient 按 driver 分流入口方法。
 */
final class OauthFactory
{
    /**
     * 根据单条 provider 配置创建 Client。
     *
     * @param string $name 配置键名（仅用于异常信息，便于定位）
     * @param array<string, mixed> $config 必须含 driver；其余键见 stub / docs
     * @throws InvalidProviderException 缺 driver 或未知 driver
     * @throws OauthConfigException 凭证不完整
     */
    public function make(string $name, array $config): OauthClientInterface
    {
        $driver = (string) ($config['driver'] ?? '');
        if ($driver === '') {
            throw new InvalidProviderException("Oauth provider [{$name}] missing driver");
        }

        // match 白名单：未列入的平台（微博/GitHub 等）故意不支持
        return match ($driver) {
            'fake' => new FakeOauthClient($name),
            'qq' => $this->wrap($driver, $this->makeQq($config)),
            // 三个微信驱动共用 makeWeixin；driver 字符串原样传入 OauthClient 做语义分流
            'weixin_qr', 'weixin_oa', 'weixin_mini' => $this->wrap($driver, $this->makeWeixin($driver, $config)),
            'alipay' => $this->wrap($driver, $this->makeAlipay($config)),
            'feishu' => $this->wrap($driver, $this->makeFeishu($config)),
            'dingtalk' => $this->wrap($driver, $this->makeDingtalk($config)),
            'wework' => $this->wrap($driver, $this->makeWework($config)),
            default => throw new InvalidProviderException("Unknown oauth driver [{$driver}] for provider [{$name}]"),
        };
    }

    /**
     * 将已配置好的 Yurun Base 包进门面；driver 决定门面行为分支。
     */
    private function wrap(string $driver, Base $sdk): OauthClient
    {
        return new OauthClient($driver, $sdk);
    }

    /**
     * QQ 互联：网站应用 appid + appkey + 回调。
     *
     * @param array<string, mixed> $config
     */
    private function makeQq(array $config): QQOAuth2
    {
        [$appId, $appSecret, $callback] = $this->requireAppCredentials($config, requireSecret: true);
        $sdk = new QQOAuth2($appId, $appSecret, $callback);
        $this->applyCommonOptions($sdk, $config);

        // display=mobile 时授权页为移动端样式；不传则 PC
        if (isset($config['display']) && $config['display'] !== null && $config['display'] !== '') {
            $sdk->display = (string) $config['display'];
        }
        if (array_key_exists('openid_mode', $config)) {
            $sdk->openidMode = OpenidModeMapper::toInt($config['openid_mode']);
        }
        // 是否在 get_user_info 链路使用 unionid（需开放平台开通）
        if (array_key_exists('is_use_union_id', $config)) {
            $sdk->isUseUnionID = (bool) $config['is_use_union_id'];
        }

        return $sdk;
    }

    /**
     * 微信：开放平台扫码 / 公众号 / 小程序共用 SDK 类。
     *
     * 小程序无 callback_url（无 redirect），故 requireCallback=false。
     *
     * @param array<string, mixed> $config
     */
    private function makeWeixin(string $driver, array $config): WeixinOAuth2
    {
        $requireCallback = $driver !== 'weixin_mini';
        [$appId, $appSecret, $callback] = $this->requireAppCredentials(
            $config,
            requireSecret: true,
            requireCallback: $requireCallback,
        );
        $sdk = new WeixinOAuth2($appId, $appSecret, $callback);
        $this->applyCommonOptions($sdk, $config);

        if (array_key_exists('openid_mode', $config)) {
            $sdk->openidMode = OpenidModeMapper::toInt($config['openid_mode']);
        }
        // getUserInfo 的 lang 参数（zh_CN / zh_TW / en）
        if (isset($config['lang']) && is_string($config['lang']) && $config['lang'] !== '') {
            $sdk->lang = $config['lang'];
        }

        return $sdk;
    }

    /**
     * 支付宝：换票/拉用户需 RSA(RSA2) 签名，app_secret 可空，但必须配置私钥。
     *
     * 优先级：app_private_key_file（文件路径）> app_private_key（PEM 字符串内容）。
     *
     * @param array<string, mixed> $config
     */
    private function makeAlipay(array $config): AlipayOAuth2
    {
        // Alipay 不强制 app_secret；签名靠应用私钥
        [$appId, , $callback] = $this->requireAppCredentials($config, requireSecret: false);
        $privateKeyFile = trim((string) ($config['app_private_key_file'] ?? ''));
        $privateKey = trim((string) ($config['app_private_key'] ?? ''));
        if ($privateKeyFile === '' && $privateKey === '') {
            throw new OauthConfigException('Alipay oauth requires app_private_key_file or app_private_key');
        }

        $sdk = new AlipayOAuth2($appId, (string) ($config['app_secret'] ?? ''), $callback);
        $this->applyCommonOptions($sdk, $config);
        // 官方推荐 RSA2；与开放平台应用签名类型保持一致
        $sdk->signType = (string) ($config['sign_type'] ?? 'RSA2');

        if ($privateKeyFile !== '') {
            $sdk->appPrivateKeyFile = $privateKeyFile;
        } else {
            $sdk->appPrivateKey = $privateKey;
        }

        // 第三方应用代商户调用时的 app_auth_token（可选）
        if (isset($config['app_auth_token']) && $config['app_auth_token'] !== null && $config['app_auth_token'] !== '') {
            $sdk->appAuthToken = (string) $config['app_auth_token'];
        }

        return $sdk;
    }

    /**
     * 飞书：app_id + app_secret；换票前 SDK 内部会再取 app_access_token。
     *
     * @param array<string, mixed> $config
     */
    private function makeFeishu(array $config): FeiShuOAuth2
    {
        [$appId, $appSecret, $callback] = $this->requireAppCredentials($config, requireSecret: true);
        $sdk = new FeiShuOAuth2($appId, $appSecret, $callback);
        $this->applyCommonOptions($sdk, $config);

        return $sdk;
    }

    /**
     * 钉钉：OAuth2 新版 login.dingtalk.com + api.dingtalk.com。
     *
     * @param array<string, mixed> $config
     */
    private function makeDingtalk(array $config): DingTalkOAuth2
    {
        [$appId, $appSecret, $callback] = $this->requireAppCredentials($config, requireSecret: true);
        $sdk = new DingTalkOAuth2($appId, $appSecret, $callback);
        $this->applyCommonOptions($sdk, $config);

        return $sdk;
    }

    /**
     * 企业微信：corpid 作 app_id，应用 secret，且必须 agent_id（授权 URL 必填）。
     *
     * @param array<string, mixed> $config
     */
    private function makeWework(array $config): WeWorkOAuth2
    {
        [$appId, $appSecret, $callback] = $this->requireAppCredentials($config, requireSecret: true);
        // 兼容 agent_id / agentid 两种键名
        $agentId = trim((string) ($config['agent_id'] ?? $config['agentid'] ?? ''));
        if ($agentId === '') {
            throw new OauthConfigException('Wework oauth requires agent_id');
        }
        $sdk = new WeWorkOAuth2($appId, $appSecret, $callback, $agentId);
        $this->applyCommonOptions($sdk, $config);

        return $sdk;
    }

    /**
     * 抽取并校验通用三元组：app_id、app_secret、callback_url。
     *
     * 兼容驼峰别名 appid / appSecret / callbackUrl，降低从其它项目迁配置成本。
     *
     * @param array<string, mixed> $config
     * @param bool $requireSecret 支付宝等可 false
     * @param bool $requireCallback 小程序可 false
     * @return array{0: string, 1: string, 2: string} [appId, appSecret, callback]
     * @throws OauthConfigException
     */
    private function requireAppCredentials(
        array $config,
        bool $requireSecret,
        bool $requireCallback = true,
    ): array {
        $appId = trim((string) ($config['app_id'] ?? $config['appid'] ?? ''));
        if ($appId === '') {
            throw new OauthConfigException('Oauth provider requires app_id');
        }

        $appSecret = trim((string) ($config['app_secret'] ?? $config['appSecret'] ?? ''));
        if ($requireSecret && $appSecret === '') {
            throw new OauthConfigException('Oauth provider requires app_secret');
        }

        $callback = trim((string) ($config['callback_url'] ?? $config['callbackUrl'] ?? ''));
        if ($requireCallback && $callback === '') {
            throw new OauthConfigException('Oauth provider requires callback_url');
        }

        return [$appId, $appSecret, $callback];
    }

    /**
     * 各平台通用可选字段：授权 scope、登录代理页 URL。
     *
     * login_agent_url：解决「开放平台只允许一个回调域名」时，先跳自有代理页再转真实 callback。
     *
     * @param array<string, mixed> $config
     */
    private function applyCommonOptions(Base $sdk, array $config): void
    {
        if (array_key_exists('scope', $config) && $config['scope'] !== null) {
            $sdk->scope = $config['scope'];
        }
        if (isset($config['login_agent_url']) && is_string($config['login_agent_url']) && $config['login_agent_url'] !== '') {
            $sdk->loginAgentUrl = $config['login_agent_url'];
        }
    }
}
