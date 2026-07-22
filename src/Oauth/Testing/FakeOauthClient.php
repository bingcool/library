<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Testing;

use Swoolefy\Library\Oauth\Contracts\OauthClientInterface;
use Swoolefy\Library\Oauth\Exception\OauthStateException;

/**
 * 无外网 Fake Client：单测 / 本地无 API Key 联调闭环。
 *
 * 行为对齐真实门面：
 * - getAuthUrl 生成可解析的假 URL，并写入 state
 * - getAccessToken 用 hash_equals 校验 storeState 与回调 state（防 CSRF 语义）
 * - getSessionKey / getWebAuthUrl / decryptData 全部可用（便于测能力分支，不抛 Unsupported）
 *
 * 注意：Fake 故意「全能」，真实 OauthClient 会对 weixin_mini / wework 做能力守卫；
 * 能力边界单测应针对 OauthFactory 产出的真实 Client，而非本类。
 */
final class FakeOauthClient implements OauthClientInterface
{
    /** 发起授权时写入，回调时与 storeState 比对 */
    private ?string $state = null;

    private ?string $openid = 'fake-openid';

    private ?string $accessToken = null;

    /** @var array<string, mixed> 最近一次「接口」结果，模拟 SDK->result */
    private array $result = [];

    private ?string $sessionKey = null;

    /**
     * @param string $providerName 配置键名，会编入假授权 URL，便于多 provider 联调区分
     */
    public function __construct(
        private readonly string $providerName = 'fake',
    ) {
    }

    /**
     * {@inheritdoc}
     * 固定返回 fake，与配置 driver 一致。
     */
    public function driver(): string
    {
        return 'fake';
    }

    /**
     * {@inheritdoc}
     *
     * 不发起 HTTP；state 未传时用 random_bytes 生成（与真实 OAuth 防 CSRF 思路一致）。
     */
    public function getAuthUrl(?string $callbackUrl = null, ?string $state = null, string|array|null $scope = null): string
    {
        // 16 字节 hex，足够测试态区分；生产真实 SDK 亦会自动生成 state
        $this->state = $state ?? bin2hex(random_bytes(8));
        $callback = $callbackUrl ?? 'https://example.test/oauth/callback';
        // scope 在真实平台可能是空格或逗号分隔；此处统一逗号便于断言
        $scopeStr = is_array($scope) ? implode(',', $scope) : (string) ($scope ?? 'fake_scope');

        return 'https://fake-oauth.test/authorize?' . http_build_query([
            'provider' => $this->providerName,
            'redirect_uri' => $callback,
            'state' => $this->state,
            'scope' => $scopeStr,
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * 技术点：使用 hash_equals 做常量时间比较，避免测试代码养成 == 比较密钥习惯。
     * 仅当 storeState 与回调 state 均非空且不一致时抛 OauthStateException。
     */
    public function getAccessToken(string $storeState = '', ?string $code = null, ?string $state = null): string
    {
        $incoming = $state ?? '';
        if ($storeState !== '' && $incoming !== '' && !hash_equals($storeState, $incoming)) {
            throw new OauthStateException('state验证失败');
        }

        $this->accessToken = 'fake-access-token';
        $this->openid = 'fake-openid';
        $this->result = [
            'access_token' => $this->accessToken,
            'openid' => $this->openid,
            'code' => $code ?? 'fake-code',
        ];

        return $this->accessToken;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo(?string $accessToken = null): array
    {
        $token = $accessToken ?? $this->accessToken ?? 'fake-access-token';
        $this->result = [
            'openid' => $this->openid,
            'nickname' => 'Fake User',
            'avatar' => 'https://fake-oauth.test/avatar.png',
            'access_token' => $token,
        ];

        return $this->result;
    }

    /**
     * {@inheritdoc}
     * 始终成功并轮换为 refreshed token，便于断言 refresh 路径。
     */
    public function refreshToken(string $refreshToken): bool
    {
        $this->accessToken = 'fake-access-token-refreshed';
        $this->result = [
            'access_token' => $this->accessToken,
            'refresh_token' => $refreshToken,
        ];

        return true;
    }

    /**
     * {@inheritdoc}
     * 非空字符串即视为有效（不探测远程）。
     */
    public function validateAccessToken(?string $accessToken = null): bool
    {
        $token = $accessToken ?? $this->accessToken;

        return is_string($token) && $token !== '';
    }

    /** {@inheritdoc} */
    public function getState(): ?string
    {
        return $this->state;
    }

    /** {@inheritdoc} */
    public function getOpenId(): ?string
    {
        return $this->openid;
    }

    /** {@inheritdoc} */
    public function getAccessTokenValue(): ?string
    {
        return $this->accessToken;
    }

    /** {@inheritdoc} */
    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * {@inheritdoc}
     * 模拟企业微信 Web 扫码入口；Fake 不限制 driver。
     */
    public function getWebAuthUrl(?string $callbackUrl = null, ?string $state = null, string $loginType = 'CorpApp'): string
    {
        $this->state = $state ?? bin2hex(random_bytes(8));
        $callback = $callbackUrl ?? 'https://example.test/oauth/callback';

        return 'https://fake-oauth.test/wework/web?' . http_build_query([
            'redirect_uri' => $callback,
            'state' => $this->state,
            'login_type' => $loginType,
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * session_key 由 js_code 派生（确定性），便于同一 js_code 断言稳定；
     * 同时写入假 openid / unionid 到 result。
     */
    public function getSessionKey(string $jsCode): string
    {
        $this->sessionKey = 'fake-session-key-' . substr(hash('sha256', $jsCode), 0, 16);
        $this->openid = 'fake-mini-openid';
        $this->result = [
            'session_key' => $this->sessionKey,
            'openid' => $this->openid,
            'unionid' => 'fake-mini-unionid',
            'js_code' => $jsCode,
        ];

        return $this->sessionKey;
    }

    /**
     * {@inheritdoc}
     *
     * 不真正 AES 解密；忽略密文参数，返回固定结构供业务联调。
     */
    public function decryptData(string $encryptedData, string $iv, string $sessionKey): array
    {
        // 显式丢弃：避免静态分析报 unused；语义上 Fake 不消费密文
        unset($encryptedData, $iv);
        $this->result = [
            'nickName' => 'Fake Mini User',
            'openId' => $this->openid ?? 'fake-mini-openid',
            'session_key' => $sessionKey,
        ];

        return $this->result;
    }

    /**
     * {@inheritdoc}
     * Fake 无底层 SDK，返回自身以便 raw() 链式探测。
     */
    public function raw(): object
    {
        return $this;
    }
}
