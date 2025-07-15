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

namespace Common\Library\Aliyun\Datahub;

use Common\Library\Exception\DatahubException;

abstract class AbstractBaseDatahub
{

    use HttpMethodTrait;

    /**
     * @var string
     */
    protected $accessId;

    /**
     * @var string
     */
    protected $accessKey;

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @var string
     */
    protected $projectId;

    /**
     * @var string
     */
    protected $topicName;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;


    /**
     * @param $accessId
     * @param $accessKey
     * @param $endpoint
     */
    public function __construct($accessId, $accessKey, $endpoint, $projectId, $topicName)
    {
        $this->accessId = $accessId;
        $this->accessKey = $accessKey;
        $this->endpoint = $endpoint;
        $this->projectId = $projectId;
        $this->topicName = $topicName;
        $this->setHtttpClient();
    }

    public function getAccessId()
    {
        return $this->accessId;
    }

    public function getAccessKey()
    {
        return $this->accessKey;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    
    /**
     * @return array
     * @throws \Exception
     */
    public function getTopic()
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}";
        $result = $this->get($uri);
        $this->errorHandle($uri, $result, []);
        return $result;
    }

    /**
     *
     * array(10) {
    ["Cursor"]=>
        string(32) "30006855198d00000000000000000000"
        ["PacketLines"]=>
        int(0)
        ["PacketRawSize"]=>
        int(0)
        ["PacketSize"]=>
        int(0)
        ["RecordTime"]=>
        int(1750407565395)
        ["Sequence"]=>
        int(0)
        ["SerialNum"]=>
        int(0)
        ["TotalLines"]=>
        int(0)
        ["TotalRawSize"]=>
        int(0)
        ["TotalSize"]=>
        int(0)
    }
     *
     * @param string $shardId 分片ID
     * @param int $sequence 点位偏移量
     * @return array
     */
    public function getCursor($shardId, int $sequence)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards/{$shardId}";
        $params = [
            'Action' => 'cursor',
            'Type' => 'SEQUENCE',
            'Sequence' => $sequence+1
        ];
        // 自动获取最新的未开始的数据
        $result = $this->post($uri, $params);

        $this->errorHandle($uri, $result, $params);

        return $result;
    }

    /**
    {"Offsets": {
    "0": {
        "BatchIndex": 0,
        "Sequence": -1,
        "SessionId": 2,
        "Timestamp": -1,
        "Version": 0
    },
    "1": {
        "BatchIndex": 0,
        "Sequence": -1,
        "SessionId": 2,
        "Timestamp": -1,
        "Version": 0
    }
    },
     * @return array
     */
    public function openSubscriptionSession(array $shardIds, string $subId)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/subscriptions/{$subId}/offsets";
        $params = [
            'Action' => 'open',
            'ShardIds' => $shardIds
        ];
        $result = $this->post($uri,$params);
        $this->errorHandle($uri, $result, $params);
        return $result;
    }

    /**
     * 消费获取结构化数据
     *
     * @param string $hardId
     * @param string $cursor
     * @param $recordSchema
     * @param int $limit
     * @return array
     */
    public function consume(string $hardId, string $cursor, $recordSchema, int $limit = 100)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards/{$hardId}";
        $result = $this->post($uri, [
            'Action' => 'sub',
            'Cursor' => $cursor,
            'Limit' => $limit
        ]);

        // 分析并获取结构化数据
        $recordList = $this->parseData($result, $recordSchema, $this->fields);

        return ['nextCursor' => $result['NextCursor'], 'recordCount' => $result['RecordCount'], 'records' => $recordList];
    }

    /**
     * @param string $shardId
     * @param string $subId
     * @param int $sequence
     * @param int $version
     * @param int $sessionId
     * @return mixed
     * @throws \Exception
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

        // 由于$shardId可能是0索引，在php数组json时，可能导致没有这个值，这里使用原生json字符串，避免json_encode时，导致索引丢失
        $result = $this->putRawBody($uri, $body);

        $params = [
            'Action' => 'commit',
            'Offsets' => [
                $shardId => [
                    'Timestamp' => $timestamp,
                    'Sequence' => $sequence,
                    'Version' => $version,
                    'SessionId' => $sessionId,
                ]
            ]
        ];

        $this->errorHandle($uri, $result, $params);
        return $result;
    }

    /**
     * @param \GuzzleHttp\Client|null $client
     * @return \GuzzleHttp\Client
     */
    public function setHtttpClient(\GuzzleHttp\Client $client =  null)
    {
        if ($client instanceof \GuzzleHttp\Client) {
            $this->httpClient = $client;
        }else {
            if (!is_object($this->httpClient)) {
                $client = new \GuzzleHttp\Client([
                    'base_uri' => $this->endpoint,
                    'timeout'  => 30.0,
                ]);
                $this->httpClient = $client;
            }
        }
        return $this->httpClient;
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * 获取分片
     *
     * @return array
     */
    public function listShards()
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/shards";
        $result = $this->get($uri);
        $this->errorHandle($uri, $result, []);
        return $result;
    }

    /**
     * @param $uri
     * @param $result
     * @param array $params
     * @return void
     * @throws \Exception
     */
    public function errorHandle($uri, $result, array $params)
    {
        if (isset($result['ErrorCode']) && !empty($result['ErrorCode'])) {
            $errorMessage = $result['ErrorMessage'] ?? '';
            throw new DatahubException("ErrorCode={$result['ErrorCode']}, errorMessage={$errorMessage}, uri={$uri}, params=".json_encode($params, JSON_UNESCAPED_UNICODE));
        }
    }
}