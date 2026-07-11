# Jwt

JWT（JSON Web Token）签发、解析与校验组件，API 风格对齐 [lcobucci/jwt](https://github.com/lcobucci/jwt)，内置于 library，命名空间为 `Swoolefy\Library\Jwt`。

支持 HMAC / RSA / ECDSA 签名，以及标准注册声明与自定义 Claim 校验。

---

## 目录结构

```
Jwt/
├── Configuration.php          # 对称/非对称/无签名 配置入口
├── Builder.php / Parser.php / Token.php / Validator.php   # 接口
├── Token/
│   ├── Builder.php            # 签发
│   ├── Parser.php             # 解析
│   ├── Plain.php              # Token 实现
│   └── RegisteredClaims.php   # iss/aud/exp/… 常量
├── Signer/
│   ├── Hmac/{Sha256,Sha384,Sha512}.php
│   ├── Rsa/{Sha256,Sha384,Sha512}.php
│   ├── Ecdsa/{Sha256,Sha384,Sha512}.php
│   ├── None.php
│   └── Key/{InMemory,LocalFileReference}.php
├── Validation/
│   ├── Validator.php
│   └── Constraint/            # SignedWith / ValidAt / IssuedBy / …
├── Encoding/                  # JoseEncoder、ChainedFormatter 等
└── README.md
```

---

## 快速开始（HMAC）

与 Test 应用 `TokenController` 一致：

```php
use DateTimeImmutable;
use Swoolefy\Library\Clock\SystemClock;
use Swoolefy\Library\Jwt\Encoding\ChainedFormatter;
use Swoolefy\Library\Jwt\Encoding\JoseEncoder;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha256;
use Swoolefy\Library\Jwt\Signer\Key\InMemory;
use Swoolefy\Library\Jwt\Token\Builder;
use Swoolefy\Library\Jwt\Token\Parser;
use Swoolefy\Library\Jwt\Token\RegisteredClaims;
use Swoolefy\Library\Jwt\Validation\Constraint\HasClaimWithValue;
use Swoolefy\Library\Jwt\Validation\Constraint\RelatedTo;
use Swoolefy\Library\Jwt\Validation\Constraint\ValidAt;
use Swoolefy\Library\Jwt\Validation\Validator;

$secret = 'your-secret-key-at-least-32-chars!!';
$algorithm = new Sha256();
$signingKey = InMemory::plainText($secret);

$now = new DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));

$token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
    ->issuedBy('http://example.com')           // iss
    ->permittedFor('http://example.org')        // aud
    ->identifiedBy('4f1g23a12aa')                // jti
    ->issuedAt($now)                            // iat
    // ->canOnlyBeUsedAfter($now->modify('+1 minute')) // nbf
    ->expiresAt($now->modify('+1 hour'))        // exp
    ->relatedTo('1234567891')                   // sub
    ->withClaim('uid', 1)                       // 自定义 claim
    ->withHeader('foo', 'bar')
    ->getToken($algorithm, $signingKey);

$jwt = $token->toString();

// 解析
$tokenObj = (new Parser(new JoseEncoder()))->parse($jwt);

// 校验（返回 bool；失败可 getErrorMsg）
$validator = new Validator();
$ok = $validator->validate(
    $tokenObj,
    new RelatedTo('1234567891'),
    new HasClaimWithValue('uid', 1),
    new ValidAt(SystemClock::fromSystemTimezone())
);

$uid = $tokenObj->claims()->get('uid');
$exp = $tokenObj->claims()
    ->get(RegisteredClaims::EXPIRATION_TIME)
    ->setTimeZone(new \DateTimeZone(date_default_timezone_get()))
    ->format('Y-m-d H:i:s');

// 或直接判断过期
if ($tokenObj->isExpired($now)) {
    // expired
}
```

试调：`GET http://127.0.0.1:9501/api/token/jwt`

---

## Configuration 入口（推荐）

一次装配 Signer / Key / Parser / Validator：

```php
use Swoolefy\Library\Jwt\Configuration;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha256;
use Swoolefy\Library\Jwt\Signer\Key\InMemory;
use Swoolefy\Library\Jwt\Validation\Constraint\SignedWith;
use Swoolefy\Library\Jwt\Validation\Constraint\ValidAt;
use Swoolefy\Library\Clock\SystemClock;

$config = Configuration::forSymmetricSigner(
    new Sha256(),
    InMemory::plainText($secret)
);

$config->setValidationConstraints(
    new SignedWith($config->signer(), $config->verificationKey()),
    new ValidAt(SystemClock::fromSystemTimezone())
);

// 签发
$token = $config->builder()
    ->issuedBy('app')
    ->expiresAt(new DateTimeImmutable('+1 hour'))
    ->withClaim('uid', 100)
    ->getToken($config->signer(), $config->signingKey());

// 解析 + 严格断言（失败抛 RequiredConstraintsViolated）
$parsed = $config->parser()->parse($token->toString());
$config->validator()->assert($parsed, ...$config->validationConstraints());
```

| 工厂方法 | 说明 |
|----------|------|
| `forSymmetricSigner` | HMAC：签发钥 = 校验钥 |
| `forAsymmetricSigner` | RSA/ECDSA：私钥签、公钥验 |
| `forUnsecuredSigner` | `alg=none`（仅调试，勿用于生产） |

非对称示例：

```php
use Swoolefy\Library\Jwt\Signer\Rsa\Sha256 as RsaSha256;

$config = Configuration::forAsymmetricSigner(
    new RsaSha256(),
    InMemory::file('/path/to/private.pem'),
    InMemory::file('/path/to/public.pem')
);
```

密钥也可：`InMemory::plainText()` / `InMemory::base64Encoded()` / `LocalFileReference::file()`。

---

## 注册声明（Registered Claims）

| Claim | Builder 方法 | 常量 |
|-------|--------------|------|
| `iss` | `issuedBy` | `RegisteredClaims::ISSUER` |
| `aud` | `permittedFor` | `AUDIENCE` |
| `jti` | `identifiedBy` | `ID` |
| `iat` | `issuedAt` | `ISSUED_AT` |
| `nbf` | `canOnlyBeUsedAfter` | `NOT_BEFORE` |
| `exp` | `expiresAt` | `EXPIRATION_TIME` |
| `sub` | `relatedTo` | `SUBJECT` |

自定义：`withClaim($name, $value)`、`withHeader($name, $value)`。

---

## 校验约束（Constraint）

| 类 | 作用 |
|----|------|
| `SignedWith` | 用指定 Signer + Key 验签 |
| `ValidAt` | 校验 iat/nbf/exp（相对 Clock） |
| `LooseValidAt` | 宽松时间校验 |
| `IssuedBy` | `iss` |
| `PermittedFor` | `aud` |
| `IdentifiedBy` | `jti` |
| `RelatedTo` | `sub` |
| `HasClaimWithValue` | 自定义 claim 精确匹配 |

```php
// validate：false + getErrorMsg()
if (!$validator->validate($token, new IssuedBy('app'))) {
    $errors = $validator->getErrorMsg();
}

// assert：失败抛异常
$validator->assert($token, new SignedWith($signer, $key), new ValidAt($clock));
```

---

## 签名算法

| 算法族 | 类 |
|--------|-----|
| HMAC | `Signer\Hmac\Sha256` / `Sha384` / `Sha512` |
| RSA | `Signer\Rsa\Sha256` / `Sha384` / `Sha512` |
| ECDSA | `Signer\Ecdsa\Sha256` / `Sha384` / `Sha512` |
| none | `Signer\None`（不安全） |

---

## 使用建议

1. **密钥强度**：HMAC 密钥足够长且保密；生产用非对称时私钥勿下发客户端。  
2. **必校验签与过期**：至少 `SignedWith` + `ValidAt`（或 `isExpired`）。  
3. **时区**：`DateTimeImmutable` / `SystemClock` 与业务时区一致，避免误判过期。  
4. **无状态认证**：JWT 适合网关/API；需强制失效时配合黑名单或短过期 + 刷新令牌。  
5. **勿用 `forUnsecuredSigner` 上生产**。

---

## 快速对照

```php
// 签发
$jwt = $config->builder()->expiresAt(...)->withClaim('uid', 1)
    ->getToken($config->signer(), $config->signingKey())->toString();

// 解析校验
$token = $config->parser()->parse($jwt);
$config->validator()->assert($token, ...$config->validationConstraints());
$uid = $token->claims()->get('uid');
```
