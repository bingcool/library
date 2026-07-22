# Oauth

第三方登录统一门面（实现于 **bingcool/library**）。设计见 [docs/Oauth.md](../../../swoolefy/docs/Oauth.md)。

## 支持的 driver

| driver | 场景 |
|--------|------|
| `qq` | QQ 网站登录 |
| `weixin_qr` | 微信开放平台网页扫码 |
| `weixin_oa` | 微信公众号网页授权 |
| `weixin_mini` | 微信小程序 `jscode2session` |
| `alipay` | 支付宝网页授权 |
| `feishu` | 飞书 |
| `dingtalk` | 钉钉 |
| `wework` | 企业微信（应用内 + Web 扫码） |
| `fake` | 单测 / 无密钥联调 |

依赖：`yurunsoft/yurun-oauth-login`。

---

## 在 Swoolefy 中使用

### 1. 配置与组件

`php cli.php create AppName` 会自动复制；存量应用可手动拷贝：

```bash
cp vendor/bingcool/swoolefy/src/Stubs/oauth.conf.stub.php \
   APP_PATH/Config/oauth.php

cp vendor/bingcool/swoolefy/src/Stubs/oauth.component.stub.php \
   APP_PATH/Config/component/oauth.php
```

组件由 `SystemEnv::loadComponents()` 自动合并，无需改 `app.php`。

```php
// Config/component/oauth.php
return [
    'oauth' => static function (): \Swoolefy\Library\Oauth\OauthManager {
        $config = include APP_PATH . '/Config/oauth.php';
        return new \Swoolefy\Library\Oauth\OauthManager($config);
    },
];
```

### 2. Controller 示例

```php
/** @var \Swoolefy\Library\Oauth\OauthManager $oauth */
$oauth = \Swoolefy\Core\Application::getApp()->get('oauth');
$client = $oauth->provider('qq');

$url = $client->getAuthUrl();
$state = $client->getState(); // 存 Session

// 回调
$client->getAccessToken($storeState, $code, $state);
$user = $client->getUserInfo();
$openid = $client->getOpenId();
```

微信公众号：`provider('weixin_oa')->getAuthUrl()`（内部走公众号授权 URL）。  
微信小程序：`getSessionKey($jsCode)` / `decryptData(...)`。  
企业微信扫码：`getWebAuthUrl()`。

> 登录必须显式 `provider('qq')` 等，无 default_provider。  
> Manager 仅作协程/请求级 DI 单例。勿进程级 static 缓存已换票的 Client。
