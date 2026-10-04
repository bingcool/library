# Encryption

基于 OpenSSL 的对称加解密。密文带 IV，CBC 额外做 HMAC-SHA256，GCM 使用 AEAD tag。用来保护配置、Token、Cookie 一类可逆数据，不用于保存密码。密码哈希用 `Hashing`。

命名空间：`Swoolefy\Library\Encryption`  
依赖：`ext-openssl`、`ext-mbstring`（或 `symfony/polyfill-mbstring`）

---

## 目录结构

```
Encryption/
├── Encrypter.php                      # 实现
├── Contracts/
│   ├── Encrypter.php                  # encrypt / decrypt
│   ├── StringEncrypter.php            # encryptString / decryptString
│   ├── EncryptException.php
│   └── DecryptException.php
└── README.md
```

---

## 支持的算法

构造函数默认 `aes-128-cbc`。密钥按字节计长度，`mb_strlen($key, '8bit')` 必须正好等于下表。

| cipher | 密钥长度 | 完整性 |
|--------|----------|--------|
| `aes-128-cbc` | 16 字节 | HMAC-SHA256 |
| `aes-256-cbc` | 32 字节 | HMAC-SHA256 |
| `aes-128-gcm` | 16 字节 | GCM tag |
| `aes-256-gcm` | 32 字节 | GCM tag |

算法名不区分大小写。长度不对或算法不在表内时，构造函数抛 `EncryptException`。

`Encrypter::generateKey($cipher)` 用 `random_bytes` 生成对应长度的原始密钥。`$cipher` 必须是上表中的算法。

---

## 用法

```php
use Swoolefy\Library\Encryption\Encrypter;

$key = Encrypter::generateKey('aes-256-gcm');
$encrypter = new Encrypter($key, 'aes-256-gcm');

$payload = $encrypter->encrypt(['user_id' => 1]);
$data = $encrypter->decrypt($payload);

$text = $encrypter->encryptString('secret');
$plain = $encrypter->decryptString($text);
```

密钥通常放在环境变量里。本类**不会**识别 Laravel 的 `base64:` 前缀，传入前要先解码成原始字节：

```php
$key = base64_decode(env('APP_ENCRYPTION_KEY'), true);
$encrypter = new Encrypter($key, 'aes-256-cbc');
```

加密和解密必须使用同一把密钥、同一种 cipher。

---

## 两个入口的区别

| 方法 | 明文 | 解密 |
|------|------|------|
| `encrypt($value)` | 先 `serialize`，可传入数组、对象 | `decrypt($payload)` |
| `encryptString($value)` | 按原始字符串加密，不序列化 | `decryptString($payload)` |

两边不能交叉调用。`encrypt()` 的结果用 `decryptString()` 解开，会得到 PHP 序列化字符串，而不是原值。`encryptString()` 的结果用 `decrypt()` 解开，会走 `unserialize`。

`encrypt($value, false)` 与 `encryptString()` 相同；`decrypt($payload, false)` 与 `decryptString()` 相同。

---

## 密文格式

返回值是一段 Base64 字符串，解码后为 JSON：

| 字段 | 含义 |
|------|------|
| `iv` | 本次加密的随机 IV（Base64） |
| `value` | OpenSSL 密文 |
| `mac` | CBC 的 HMAC-SHA256；GCM 为空字符串 |
| `tag` | GCM 的认证 tag（Base64）；CBC 为空 |

每次 `encrypt` 都会重新生成 IV，同一明文多次加密的结果不同。

---

## 失败

| 异常 | 场景 |
|------|------|
| `EncryptException` | 算法或密钥长度不合法；OpenSSL 加密失败；密文 JSON 编码失败 |
| `DecryptException` | 载荷不是合法 JSON、缺字段或 IV 长度不对；CBC 的 MAC 不匹配；GCM tag 长度不是 16 字节；密钥或算法与加密时不一致导致解密失败 |

CBC 会先校验 MAC，通过后才解密。GCM 由 OpenSSL 校验 tag。不要把不可信字符串直接 `unserialize`；只有 `decrypt()` 在校验通过之后才会反序列化。

---

## 注意点

1. 密钥是原始字节，不是十六进制或 Base64 字符串本身。16 个字符的 ASCII 密钥只满足 `aes-128-*`。
2. `getKey()` 返回构造时传入的密钥，日志和异常信息里不要打印它。
3. `decrypt()` 会 `unserialize`。只解密本组件、同一密钥产出的载荷。
4. 更换密钥后，旧密文无法解密，需要业务侧自行迁移。
