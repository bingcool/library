<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Aliyun\Datahub;

use Swoolefy\Library\Exception\DatahubException;

/**
 * DataHub HTTP 客户端基类：Topic 查询、Cursor、订阅位点、消费解析。
 *
 * 子类可通过 `$fields` 指定消费时只解析部分列；DTS 相关操作判断见 HttpMethodTrait。
 *
 * @see HttpMethodTrait
 */
abstract class AbstractBaseDatahub
{
    use HttpMethodTrait;

    /** @var string AccessKey ID */
    protected $accessId;

    /** @var string AccessKey Secret */
    protected $accessKey;

    /** @var string DataHub Endpoint */
    protected $endpoint;

    /** @var string Project 名称 */
    protected $projectId;

    /** @var string Topic 名称 */
    protected $topicName;

    /**
     * 消费时仅解析这些字段名；为空则解析 RecordSchema 中全部字段。
     *
     * @var array
     */
    protected $fields = [];

    /** @var \GuzzleHttp\Client|null */
    protected $httpClient;

    /**
     * @param string $accessId
     * @param string $accessKey
     * @param string $endpoint
     * @param string $projectId
     * @param string $topicName
     */
    public function __construct($accessId, $accessKey, $endpoint, $projectId, $topicName)
    {
        $this->accessId = $accessId;
        $this->accessKey = $accessKey;
        $this->endpoint = $endpoint;
        $this->projectId = $projectId;
        $this->topicName = $topicName;
        // 方法名历史拼写 setHtttpClient，对外保持兼容不改名
        $this->setHtttpClient();
    }

    /**
     * @return string
     */
    public function getAccessId()
    {
        return $this->accessId;
    }

    /**
     * @return string
     */
    public function getAccessKey()
    {
        return $this->accessKey;
    }

    /**
     * @return string
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * 获取当前 Topic 元信息（含 RecordSchema 等）。
     *
     * @return array
     * @throws DatahubException
     */
    public function getTopic()
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}";
        $result = $this->get($uri);
        $this->errorHandle($uri, $result, []);

        return $result;
    }

    /**
     * 按 Sequence 获取 Cursor，便于从指定位点续读。
     *
     * 请求 Type=SEQUENCE，Sequence 参数为 `$sequence + 1`（从下一偏移开始）。
     *
     * 返回示例字段：Cursor / Sequence / RecordTime / TotalSize …
     *
     * @param string $shardId 分片 ID
     * @param int $sequence 点位偏移量（会话中已提交的 Sequence）
     * @return array
     * @throws DatahubException
     */
    public function getCursor($shardId, int $sequence)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards/{$shardId}";
        $params = [
            'Action' => 'cursor',
            'Type' => 'SEQUENCE',
            'Sequence' => $sequence + 1,
        ];
        $result = $this->post($uri, $params);
        $this->errorHandle($uri, $result, $params);

        return $result;
    }

    /**
     * 打开订阅 Session，获取各 Shard 的 Offset（Sequence / Version / SessionId）。
     *
     * @param array $shardIds 分片 ID 列表
     * @param string $subId 订阅 ID
     * @return array
     * @throws DatahubException
     */
    public function openSubscriptionSession(array $shardIds, string $subId)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/subscriptions/{$subId}/offsets";
        $params = [
            'Action' => 'open',
            'ShardIds' => $shardIds,
        ];
        $result = $this->post($uri, $params);
        $this->errorHandle($uri, $result, $params);

        return $result;
    }

    /**
     * 拉取并解析结构化记录。
     *
     * @param string $hardId Shard ID（历史参数名，语义为 shardId）
     * @param string $cursor Cursor 句柄
     * @param array $recordSchema Topic 的 RecordSchema（已 json_decode）
     * @param int $limit 单次拉取条数上限
     * @return array{nextCursor: mixed, recordCount: mixed, records: array}
     */
    public function consume(string $hardId, string $cursor, $recordSchema, int $limit = 100)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards/{$hardId}";
        $result = $this->post($uri, [
            'Action' => 'sub',
            'Cursor' => $cursor,
            'Limit' => $limit,
        ]);

        $recordList = $this->parseData($result, $recordSchema, $this->fields);

        return [
            'nextCursor' => $result['NextCursor'],
            'recordCount' => $result['RecordCount'],
            'records' => $recordList,
        ];
    }

    /**
     * 提交订阅消费位点。
     *
     * 使用原生 JSON 字符串提交，避免 shardId 为 "0" 时被 json_encode 当成数组下标丢失。
     *
     * @param string $shardId
     * @param string $subId
     * @param int $sequence
     * @param int $version
     * @param int $sessionId
     * @return mixed
     * @throws DatahubException
     */
    public function commitSubscriptionOffset(string $shardId, string $subId, int $sequence, int $version, int $sessionId)
    {
        $timestamp = time();
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/subscriptions/{$subId}/offsets";
        $body = <<<JSON
{
    "Action": "commit",
    "Offsets": {
        "$shardId": {
            "Timestamp": $timestamp,
            "Sequence": $sequence,
            "Version": $version,
            "SessionId": $sessionId
        }
    }
}
JSON;

        $result = $this->putRawBody($uri, $body);

        $params = [
            'Action' => 'commit',
            'Offsets' => [
                $shardId => [
                    'Timestamp' => $timestamp,
                    'Sequence' => $sequence,
                    'Version' => $version,
                    'SessionId' => $sessionId,
                ],
            ],
        ];

        $this->errorHandle($uri, $result, $params);

        return $result;
    }

    /**
     * 设置或懒创建 Guzzle Client。
     *
     * 注意：方法名拼写为 setHtttpClient（三处 t），为兼容已有调用保留。
     *
     * @param \GuzzleHttp\Client|null $client
     * @return \GuzzleHttp\Client
     */
    public function setHtttpClient(?\GuzzleHttp\Client $client = null)
    {
        if ($client instanceof \GuzzleHttp\Client) {
            $this->httpClient = $client;
        } else {
            if (!is_object($this->httpClient)) {
                $client = new \GuzzleHttp\Client([
                    'base_uri' => $this->endpoint,
                    'timeout' => 30.0,
                ]);
                $this->httpClient = $client;
            }
        }

        return $this->httpClient;
    }

    /**
     * @return \GuzzleHttp\Client|null
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * 列出当前 Topic 下全部分片。
     *
     * @return array
     * @throws DatahubException
     */
    public function listShards()
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards";
        $result = $this->get($uri);
        $this->errorHandle($uri, $result, []);

        return $result;
    }

    /**
     * 若响应含 ErrorCode 则抛出 DatahubException。
     *
     * @param string $uri
     * @param mixed $result
     * @param array $params 请求参数（写入异常信息便于排查）
     * @return void
     * @throws DatahubException
     */
    public function errorHandle($uri, $result, array $params)
    {
        if (isset($result['ErrorCode']) && !empty($result['ErrorCode'])) {
            $errorMessage = $result['ErrorMessage'] ?? '';
            throw new DatahubException(
                "ErrorCode={$result['ErrorCode']}, errorMessage={$errorMessage}, uri={$uri}, params="
                . json_encode($params, JSON_UNESCAPED_UNICODE)
            );
        }
    }
}
