# Aliyun

阿里云相关 SDK 封装。当前包含 **DataHub** 客户端（基于 Guzzle + DataHub HTTP 签名）。

命名空间：`Swoolefy\Library\Aliyun\Datahub`

---

## DataHub 目录结构

```
Aliyun/
└── Datahub/
    ├── DatahubConst.php           # TUPLE / BLOB 常量
    ├── DatahubConfigDto.php       # AccessKey / Endpoint / Project / Topic
    ├── HttpMethodTrait.php        # HTTP 方法、签名、记录解析、DTS 操作判断
    ├── AbstractBaseDatahub.php    # 查询 Topic、Cursor、订阅、消费、提交位点
    └── DatahubTool.php            # 创建/删除 Topic、创建订阅
    └── EADME.md
```

---

## 快速使用

```php
use Swoolefy\Library\Aliyun\Datahub\DatahubConfigDto;
use Swoolefy\Library\Aliyun\Datahub\DatahubTool;
use Swoolefy\Library\Aliyun\Datahub\DatahubConst;

$dto = new DatahubConfigDto();
$dto->accessId  = 'your-access-id';
$dto->accessKey = 'your-access-key';
$dto->endpoint  = 'https://dh-cn-hangzhou.aliyuncs.com';
$dto->projectId = 'your_project';
$dto->topicName = 'your_topic';

$tool = new DatahubTool($dto);

// 管理：创建订阅
$sub = $tool->createSubscription('order binlog sub');

// 消费侧：也可继承 AbstractBaseDatahub 自定义 $fields
$topicInfo = $tool->getTopic();
$schema = json_decode($topicInfo['RecordSchema'], true);
$session = $tool->openSubscriptionSession(['0'], $sub['SubId']);
$cursor  = $tool->getCursor('0', $session['Offsets']['0']['Sequence']);
$result  = $tool->consume('0', $cursor['Cursor'], $schema, 100);
```

消费循环完整示例见：`swoolefy/Test/WorkerDaemon/Datahub/OrderProcessDatahub.php`。

---

## 核心 API

| 方法 | 说明 |
|------|------|
| `getTopic()` | Topic 元信息（含 RecordSchema） |
| `listShards()` | 分片列表 |
| `getCursor($shardId, $sequence)` | 按 Sequence 取 Cursor（内部 `Sequence+1`） |
| `openSubscriptionSession($shardIds, $subId)` | 打开订阅会话，取 Offset |
| `consume($shardId, $cursor, $schema, $limit)` | 拉取并解析记录 |
| `commitSubscriptionOffset(...)` | 提交位点（原生 JSON，兼容 shardId=`0`） |
| `createTopic(...)` / `deleteTopic(...)` | 管理 Topic（`DatahubTool`） |
| `createSubscription($comment)` | 创建订阅（`DatahubTool`） |
| `isInsertOperation` / `isUpdateOperation` / `isDeleteOperation` | 判断 DTS 操作标志 I/U/D |

---

## 签名说明

请求头含 `Date`（GMT）、`Content-Type`、`x-datahub-*`；`Authorization` 格式：

```text
DATAHUB {accessId}:{base64(hmac-sha1(canonString, accessKey))}
```

实现见 `HttpMethodTrait::buildSignature()`，文档：[DataHub 签名](https://help.aliyun.com/zh/datahub/developer-reference/nerbcz)。

---

## 注意点

1. **`setHtttpClient`**：历史方法名（三处 `t`），勿随意改名，以免破坏兼容。  
2. **`commitSubscriptionOffset`**：必须用 `putRawBody` 提交字符串 JSON，避免 `json_encode` 把 `"0"` 键吃掉。  
3. **`$fields`**：子类可设字段白名单；默认空数组表示解析全部列。  
4. **Guzzle**：依赖 `guzzlehttp/guzzle`；默认 `timeout=30`，可通过 `setHtttpClient($client)` 注入自定义 Client。
