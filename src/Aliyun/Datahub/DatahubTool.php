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

class DatahubTool extends AbstractBaseDatahub
{
    /**
     * @param DatahubConfigDto $config
     */
    public function __construct(DatahubConfigDto $config)
    {
        parent::__construct($config->accessId, $config->accessKey, $config->endpoint, $config->projectId, $config->topicName);
    }

    /**
     * 创建topic
     *
     * $recordSchema = [
        "fields" => [
            [
                'name' => "username",
                'type' => "STRING",
                'comment' => "用户名"
            ],
            [
                'name' => "sex",
                'type' => "TINYINT",
                'comment' => "性别"
            ],
        ]
    ];

        // 调用创建topic
        $result = $datahub->createTopic("bing_test",1,1,DatahubConst::RecordTypeTuple,$recordSchema,"测试topic",);
     
     *
     * @param string $topicName topic名称
     * @param int $ShardCount 初始shard数目
     * @param int $Lifecycle 数据存储生命周期
     * @param string $recordType 记录类型 tuple blob
     * @param array $recordSchema 记录结构
     * @param string $comment 描述
     * @return array
     * @throws \Exception
     */
    public function createTopic(string $topicName, int $shardCount, int $lifecycle, string $recordType, array $recordSchema, string $comment)
    {
        $uri = "/projects/{$this->projectId}/topics/{$topicName}";

        $params = [
            'Action' => 'create',
            'ShardCount' => $shardCount,
            'Lifecycle' => $lifecycle,
            'Comment' => $comment
        ];
        
        if ($recordType == DatahubConst::RecordTypeTuple) {
            $params['RecordType'] = $recordType;
            $params['RecordSchema'] = json_encode($recordSchema, JSON_UNESCAPED_UNICODE);
        }else if ($recordType == DatahubConst::RecordTypeBlob) {
            $params['RecordType'] = $recordType;
        } else {
            throw new DatahubException('[createTopic] argument of `recordType` is error');
        }

        $result = $this->post($uri, $params);
        if (!empty($result)) {
            $this->errorHandle($uri, $result, $params);
        }
    }

    /**
     * 删除topic(慎用)
     * @param string $topicName 初始shard数目
     * @return array
     * @throws \Exception
     */
    public function deleteTopic(string $topicName)
    {
        $uri = "/projects/{$this->projectId}/topics/$topicName";
        $this->delete($uri);
    }

    /**
     * 创建订阅subId
     *
     * @param string $description
     * @return mixed
     */
    public function createSubscription(string $description)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/subscriptions";

        $params = [
            'Action' => 'create',
            'Comment' => $description
        ];

        $result = $this->post($uri, $params);
        $this->errorHandle($uri, $result, $params);
        return $result;
    }


}