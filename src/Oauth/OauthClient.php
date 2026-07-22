<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth;

use Swoolefy\Library\Oauth\Contracts\OauthClientInterface;
use Swoolefy\Library\Oauth\Exception\OauthApiException;
use Swoolefy\Library\Oauth\Exception\OauthStateException;
use Swoolefy\Library\Oauth\Exception\UnsupportedOauthCapabilityException;
use Yurun\OAuthLogin\ApiException;
use Yurun\OAuthLogin\Base;
use Yurun\OAuthLogin\Weixin\OAuth2 as WeixinOAuth2;
use Yurun\OAuthLogin\WeWork\OAuth2 as WeWorkOAuth2;

/**
 * 真实平台 OauthClient 门面：包装 Yurun\OAuthLogin\*\OAuth2。
 *
 * 设计要点：
 * 1. 用 driver 固定入口语义（尤其微信：扫码 / 公众号 / 小程序共用一个 SDK 类）
 * 2. 将 Yurun ApiException / state 校验失败统一映射为本模块异常树
 * 3. 平台特有能力（企微扫码、小程序 session）显式方法 + 能力守卫，避免误调
 *
 * 可变态说明：本对象内嵌的 $sdk 持有 accessToken、openid、state、result；
 * 一次完整「跳转 → 回调换票 → 拉用户」应复用同一 Client 实例。
 */
final class OauthClient implements OauthClientInterface
{
    /**
     * @param string $driver 配置中的 driver（如 weixin_oa），决定 getAuthUrl 等分流逻辑
     * @param Base $sdk 已注入 appid/secret/callback 的 Yurun OAuth2 实例
     */
    public function __construct(
        private readonly string $driver,
        private readonly Base $sdk,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * 返回配置 driver，而非 Yurun 类名，便于业务日志与路由分支。
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     *
     * 技术点：微信开放平台扫码与公众号授权 URL 不同。
     * - weixin_qr → SDK getAuthUrl（open.weixin.qq.com/connect/qrconnect）
     * - weixin_oa → SDK getWeixinAuthUrl（connect/oauth2/authorize）
     * - 其它跳转式平台 → 各自 getAuthUrl
     * - weixin_mini 禁止调用（无浏览器跳转码流）
     */
    public function getAuthUrl(?string $callbackUrl = null, ?string $state = null, string|array|null $scope = null): string
    {
        $this->assertNotWeixinMini('getAuthUrl');

        return $this->wrap(function () use ($callbackUrl, $state, $scope): string {
            // 公众号：强制走 getWeixinAuthUrl，避免业务误用扫码 URL 导致授权页异常
            if ($this->driver === 'weixin_oa' && $this->sdk instanceof WeixinOAuth2) {
                return $this->sdk->getWeixinAuthUrl($callbackUrl, $state, $scope);
            }

            return $this->sdk->getAuthUrl($callbackUrl, $state, $scope);
        });
    }

    /**
     * {@inheritdoc}
     *
     * 技术点：Yurun Base::getAccessToken 会先 checkState，失败抛 InvalidArgumentException；
     * 此处转换为 OauthStateException，便于业务统一 catch OauthException。
     * code/state 建议显式传入（Swoole 下勿依赖 $_GET 全局）。
     */
    public function getAccessToken(string $storeState = '', ?string $code = null, ?string $state = null): string
    {
        $this->assertNotWeixinMini('getAccessToken');

        return $this->wrap(function () use ($storeState, $code, $state): string {
            try {
                // 成功后 SDK 会写入 $this->sdk->accessToken / openid / result
                return (string) $this->sdk->getAccessToken($storeState, $code, $state);
            } catch (\InvalidArgumentException $e) {
                // Yurun 文案含「state」时视为 CSRF 校验失败
                if (str_contains($e->getMessage(), 'state')) {
                    throw new OauthStateException($e->getMessage(), (int) $e->getCode(), $e);
                }
                throw $e;
            }
        });
    }

    /**
     * {@inheritdoc}
     *
     * 返回平台原始数组（字段因厂商而异），本期不做 OauthUser 归一化。
     * 小程序无此接口，应走 getSessionKey / decryptData。
     */
    public function getUserInfo(?string $accessToken = null): array
    {
        $this->assertNotWeixinMini('getUserInfo');

        return $this->wrap(function () use ($accessToken): array {
            $info = $this->sdk->getUserInfo($accessToken);

            return is_array($info) ? $info : [];
        });
    }

    /**
     * {@inheritdoc}
     *
     * 部分平台（如企业微信）refreshToken 恒为 false；Alipay 成功时 SDK 可能返回 token 字符串，
     * 此处统一 (bool) 化，非空字符串视为成功。
     */
    public function refreshToken(string $refreshToken): bool
    {
        return $this->wrap(function () use ($refreshToken): bool {
            $result = $this->sdk->refreshToken($refreshToken);

            return (bool) $result;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function validateAccessToken(?string $accessToken = null): bool
    {
        return $this->wrap(function () use ($accessToken): bool {
            return (bool) $this->sdk->validateAccessToken($accessToken);
        });
    }

    /**
     * {@inheritdoc}
     *
     * getAuthUrl / getWebAuthUrl 后可读；用于写入 Session 供回调比对。
     */
    public function getState(): ?string
    {
        $state = $this->sdk->state ?? null;

        return $state === null || $state === '' ? null : (string) $state;
    }

    /**
     * {@inheritdoc}
     *
     * 换票或 getSessionKey 成功后由 SDK 按 openidMode 写入；取值字段受配置 openid_mode 影响。
     */
    public function getOpenId(): ?string
    {
        $openid = $this->sdk->openid ?? null;

        return $openid === null || $openid === '' ? null : (string) $openid;
    }

    /**
     * {@inheritdoc}
     *
     * 与 getAccessToken() 返回值相同语义，但用于「已换票后再次读取」而不触发二次请求。
     */
    public function getAccessTokenValue(): ?string
    {
        $token = $this->sdk->accessToken ?? null;

        return $token === null || $token === '' ? null : (string) $token;
    }

    /**
     * {@inheritdoc}
     *
     * SDK 最近一次 HTTP 响应解码结果；排障或取 unionid 等附加字段时使用。
     */
    public function getResult(): array
    {
        $result = $this->sdk->result ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritdoc}
     *
     * 企业微信「Web 扫码登录」与应用内 OAuth 入口不同：
     * - getAuthUrl → open.weixin.qq.com/connect/oauth2/authorize（应用内）
     * - getWebAuthUrl → login.work.weixin.qq.com/wwlogin/sso/login（扫码）
     *
     * @throws UnsupportedOauthCapabilityException 非 wework driver
     */
    public function getWebAuthUrl(?string $callbackUrl = null, ?string $state = null, string $loginType = 'CorpApp'): string
    {
        if ($this->driver !== 'wework' || !$this->sdk instanceof WeWorkOAuth2) {
            throw new UnsupportedOauthCapabilityException(
                "Driver [{$this->driver}] does not support getWebAuthUrl (wework only)"
            );
        }

        return $this->wrap(fn (): string => $this->sdk->getWebAuthUrl($callbackUrl, $state, $loginType));
    }

    /**
     * {@inheritdoc}
     *
     * 对应微信小程序 wx.login 拿到的 js_code → jscode2session。
     * 成功后可读 getOpenId()；session_key 作为返回值，勿落库明文长期存。
     *
     * @throws UnsupportedOauthCapabilityException 非 weixin_mini
     */
    public function getSessionKey(string $jsCode): string
    {
        if ($this->driver !== 'weixin_mini' || !$this->sdk instanceof WeixinOAuth2) {
            throw new UnsupportedOauthCapabilityException(
                "Driver [{$this->driver}] does not support getSessionKey (weixin_mini only)"
            );
        }

        return $this->wrap(fn (): string => (string) $this->sdk->getSessionKey($jsCode));
    }

    /**
     * {@inheritdoc}
     *
     * 注意：Yurun 方法名为 descryptData（历史拼写），门面对外使用正确拼写 decryptData。
     *
     * @throws UnsupportedOauthCapabilityException 非 weixin_mini
     */
    public function decryptData(string $encryptedData, string $iv, string $sessionKey): array
    {
        if ($this->driver !== 'weixin_mini' || !$this->sdk instanceof WeixinOAuth2) {
            throw new UnsupportedOauthCapabilityException(
                "Driver [{$this->driver}] does not support decryptData (weixin_mini only)"
            );
        }

        return $this->wrap(function () use ($encryptedData, $iv, $sessionKey): array {
            // 调用 SDK 历史方法名 descryptData（AES-128-CBC）
            $data = $this->sdk->descryptData($encryptedData, $iv, $sessionKey);

            return is_array($data) ? $data : [];
        });
    }

    /**
     * {@inheritdoc}
     *
     * 逃生舱：需要调用 SDK 未封装的字段/方法时使用（如 WeWork::getUserDetail）。
     * 直接操作 raw 时异常不会自动包装为 OauthApiException。
     */
    public function raw(): object
    {
        return $this->sdk;
    }

    /**
     * 小程序无「浏览器跳转授权码」流程，拦截易误用的网页接口。
     *
     * @throws UnsupportedOauthCapabilityException
     */
    private function assertNotWeixinMini(string $method): void
    {
        if ($this->driver === 'weixin_mini') {
            throw new UnsupportedOauthCapabilityException(
                "Driver [weixin_mini] does not support {$method}; use getSessionKey / decryptData"
            );
        }
    }

    /**
     * 统一异常边界：平台 API 错误 → OauthApiException；state → OauthStateException；
     * 已是本模块异常则原样抛出，避免二次包装丢失类型。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (OauthStateException|UnsupportedOauthCapabilityException $e) {
            throw $e;
        } catch (ApiException $e) {
            // Yurun 平台返回 errcode/errmsg 时抛出
            throw OauthApiException::fromThrowable($e);
        } catch (\InvalidArgumentException $e) {
            // 兜底：部分路径可能在 wrap 外层之外再次抛出 state 相关异常
            if (str_contains($e->getMessage(), 'state')) {
                throw new OauthStateException($e->getMessage(), (int) $e->getCode(), $e);
            }
            throw $e;
        }
    }
}
