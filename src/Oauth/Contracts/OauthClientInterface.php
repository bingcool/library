<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Contracts;

/**
 * 第三方 OAuth 客户端统一门面。
 *
 * 实现类：
 * - {@see \Swoolefy\Library\Oauth\OauthClient} 包装 Yurun SDK
 * - {@see \Swoolefy\Library\Oauth\Testing\FakeOauthClient} 无外网假实现
 *
 * 约定：
 * - 跳转式登录：getAuthUrl →（用户授权）→ getAccessToken → getUserInfo
 * - 微信小程序：仅 getSessionKey / decryptData
 * - 企业微信扫码：getWebAuthUrl（非 getAuthUrl）
 */
interface OauthClientInterface
{
    /**
     * 配置中的 driver 名（如 qq / weixin_oa / fake），用于日志与能力分支。
     */
    public function driver(): string;

    /**
     * 获取授权跳转 URL。
     *
     * weixin_oa 实现必须映射到公众号授权地址（非开放平台扫码）。
     * weixin_mini 应抛 UnsupportedOauthCapabilityException。
     *
     * @param string|null $callbackUrl 覆盖构造时 callback；null 用配置值
     * @param string|null $state 防 CSRF；null 则由 SDK/Fake 自动生成，之后 getState() 可读
     * @param string|array|null $scope 权限列表；形态随平台（字符串或数组）
     */
    public function getAuthUrl(?string $callbackUrl = null, ?string $state = null, string|array|null $scope = null): string;

    /**
     * 用授权 code 换 access_token，并校验 state（防 CSRF）。
     *
     * @param string $storeState 发起授权时保存的 state（通常来自 Session）
     * @param string|null $code 回调 code；Swoole 下务必显式传入，勿依赖 $_GET
     * @param string|null $state 回调带回的 state
     */
    public function getAccessToken(string $storeState = '', ?string $code = null, ?string $state = null): string;

    /**
     * 拉取用户资料（平台原始结构，字段不统一）。
     *
     * @param string|null $accessToken null 时使用换票后缓存在 Client 内的 token
     * @return array<string, mixed>
     */
    public function getUserInfo(?string $accessToken = null): array;

    /**
     * 刷新 access_token；平台不支持时返回 false。
     */
    public function refreshToken(string $refreshToken): bool;

    /**
     * 校验 access_token 是否仍有效。
     */
    public function validateAccessToken(?string $accessToken = null): bool;

    /**
     * 当前 state（getAuthUrl / getWebAuthUrl 后可读）。
     */
    public function getState(): ?string;

    /**
     * 当前 openid / userid（换票或 session 成功后可读；受 openid_mode 影响）。
     */
    public function getOpenId(): ?string;

    /**
     * 当前持有的 access_token（换票后可读，不触发二次请求）。
     */
    public function getAccessTokenValue(): ?string;

    /**
     * 最近一次接口原始结果（排障 / 取附加字段）。
     *
     * @return array<string, mixed>
     */
    public function getResult(): array;

    /**
     * 企业微信 Web 扫码登录 URL（login.work.weixin.qq.com）。
     *
     * 非 wework 实现应抛 UnsupportedOauthCapabilityException（Fake 除外）。
     *
     * @param string $loginType 官方文档 login_type，默认 CorpApp
     */
    public function getWebAuthUrl(?string $callbackUrl = null, ?string $state = null, string $loginType = 'CorpApp'): string;

    /**
     * 微信小程序：js_code → session_key（jscode2session）。
     *
     * 非 weixin_mini 应抛 UnsupportedOauthCapabilityException（Fake 除外）。
     */
    public function getSessionKey(string $jsCode): string;

    /**
     * 微信小程序：解密 wx.getUserInfo / getPhoneNumber 等敏感数据。
     *
     * @return array<string, mixed>
     */
    public function decryptData(string $encryptedData, string $iv, string $sessionKey): array;

    /**
     * 底层 SDK 实例（Yurun OAuth2）或 Fake 自身；逃生舱，异常不自动包装。
     */
    public function raw(): object;
}
