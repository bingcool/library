# FileStorageSystem

对象存储统一门面（实现于 **bingcool/library**）。设计见 [docs/fileStorageSystem.md](../../../swoolefy/docs/fileStorageSystem.md)。

## 能力

| Driver | Store | Metadata | Url | Multipart | Client |
|--------|-------|----------|-----|-----------|--------|
| local | ✅ | ✅ | 有限 | 流式落盘 | ❌ |
| fake | ✅ | ✅ | 假 URL | 内存直写 | ❌ |
| aws_s3 | ✅ | ✅ | ✅ | 真分片 | ✅ |
| aliyun_oss | ✅ | ✅ | ✅ | 真分片 | ✅ |
| tengxun_cos | ✅ | ✅ | ✅ | 真分片 | ✅ |

---

## 在 Swoolefy 中使用

### 1. 准备配置与组件

`php cli.php create AppName` 会自动复制；存量应用可手动拷贝：

```bash
# 盘配置
cp vendor/bingcool/swoolefy/src/Stubs/file_storage_system.conf.stub.php \
   APP_PATH/Config/file_storage_system.php

# DI 组件（注册名 file_storage）
cp vendor/bingcool/swoolefy/src/Stubs/file_storage.component.stub.php \
   APP_PATH/Config/component/file_storage.php
```

按需改 `Config/file_storage_system.php`（`default_provider`、云密钥环境变量等）。  
组件由 `SystemEnv::loadComponents()` 自动合并加载，无需再改 `app.php`。

组件闭包约定（已在 stub 中）：

```php
// Config/component/file_storage.php
return [
    'file_storage' => static function (): FileStorageManager {
        $config = include APP_PATH . '/Config/file_storage_system.php';
        return new FileStorageManager($config);
    },
];
```

> 每请求/协程通过 DI `get` 取 Manager 即可。勿在进程内 `static` 缓存带 STS 可变状态的 Manager。

### 2. Controller / Service 用例（推荐 DI）

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Library\FileStorageSystem\Exception\FileStorageException;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;

class UploadController extends BController
{
    public function upload()
    {
        /** @var FileStorageManager $mgr */
        $mgr = Application::getApp()->get('file_storage');

        // 默认盘（file_storage_system.php → default_provider）
        $disk = $mgr->disk();
        // 或指定盘：$mgr->disk('aws_s3') / disk('aliyun_oss') / disk('tengxun_cos')

        try {
            // 小文件：字符串写入
            $disk->putObject('demo/hello.txt', 'hello swoolefy', [
                'mime' => 'text/plain',
            ]);

            // 读回
            $body = $disk->getObject('demo/hello.txt');

            // 元数据：size / mime / etag / checksum / created / modified
            $meta = $disk->getMetadata('demo/hello.txt');

            // 大文件：本地路径或 stream → multipart（云为真分片；local 为 temp+rename）
            // $meta = $disk->putObjectMultipart('demo/big.bin', '/tmp/big.bin');

            // 上传流（如 UploadedFile 打开的 resource）
            // $fp = fopen($uploadedPath, 'rb');
            // $disk->putObjectStream('demo/upload.bin', $fp, ['mime' => 'application/octet-stream']);
            // fclose($fp);

            // 预签名 URL（云盘；local 不支持会抛 ObjectUrlNotSupportedException）
            $signedUrl = $disk->signObjectUrl('demo/hello.txt', 600);
            // 或绝对过期时刻：
            // $signedUrl = $disk->getTemporaryUrl('demo/hello.txt', '2030-05-05 17:50:32');

            // local 且配置了 public_base_url 时可用公开 URL
            // $publicUrl = $disk->getObjectUrl('demo/hello.txt');

            return $this->returnJson([
                'body' => $body,
                'size' => $meta->size,
                'mime' => $meta->mime,
                'etag' => $meta->etag,
                'signed_url' => $signedUrl,
            ]);
        } catch (FileStorageException $e) {
            return $this->returnJson(['error' => $e->getMessage()], 500);
        }
    }

    /** 切换到指定云盘上传 */
    public function uploadToOss()
    {
        $disk = Application::getApp()->get('file_storage')->disk('aliyun_oss');
        $disk->putObject('orders/2026/receipt.pdf', file_get_contents('/tmp/receipt.pdf'), [
            'mime' => 'application/pdf',
        ]);

        return $this->returnJson([
            'url' => $disk->signObjectUrl('orders/2026/receipt.pdf', 3600),
        ]);
    }
}
```

### 3. 脚本 / 单测中直接 new（不经 DI）

```php
use Swoolefy\Library\FileStorageSystem\FileStorageManager;

$mgr = new FileStorageManager(include APP_PATH . '/Config/file_storage_system.php');
$disk = $mgr->disk('fake'); // 单测可用 fake，无 IO、无云

$disk->putObject('t.txt', '1');
assert($disk->getObject('t.txt') === '1');
```

### 4. 常用 API 速查

```php
$disk = Application::getApp()->get('file_storage')->disk();

$disk->putObject($path, $contents, $options = []);
$disk->putObjectStream($path, $resource, $options = []);
$disk->getObject($path);                 // string
$disk->getObjectStream($path);           // resource
$disk->fileExists($path);
$disk->delete($path);
$disk->copy($from, $to);
$disk->move($from, $to);
$disk->listContents($prefix = '', $deep = false);

$disk->getMetadata($path);
$disk->fileSize($path);
$disk->mimeType($path);
$disk->lastModified($path);              // 同 getTimestamp()

$disk->putObjectMultipart($path, $fileOrStream, $options = []);
$disk->signObjectUrl($path, $timeoutSeconds);
$disk->getTemporaryUrl($path, 'Y-m-d H:i:s');
$disk->getObjectUrl($path);              // 公共读 / local public_base_url

// 仅云盘：底层官方 SDK Client（高级 API）
if ($disk->supports(\Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface::class)) {
    $client = $disk->getClient();
}
```

业务侧建议统一：`catch (FileStorageException $e)`。

---

## Composer SDK

构造对应 Adapter 时会检测官方入口类；缺失则抛 `MissingSdkException`，提示安装：

| Driver | 检测类 | 安装命令 |
|--------|--------|----------|
| local | `League\Flysystem\Filesystem` | `composer require league/flysystem` |
| aws_s3 | `Aws\S3\S3Client` | `composer require aws/aws-sdk-php` |
| aliyun_oss | `AlibabaCloud\Oss\V2\Client` | `composer require alibabacloud/oss-v2` |
| tengxun_cos | `Qcloud\Cos\Client` | `composer require qcloud/cos-sdk-v5` |

（`bingcool/library` 已 require 上述包；应用侧若未同步传递依赖，按提示补装即可。）

---

## 测试（swoolefy）

```bash
./vendor/bin/phpunit --filter FileStorageSystem
```

云单测仅验证构造 / Capability，不访问真实桶。
