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
 * DataHub 管理类工具：创建/删除 Topic、创建订阅。
 *
 * 消费相关能力继承自 {@see AbstractBaseDatahub}。
 */
class DatahubTool extends AbstractBaseDatahub
{
    /**
     * @param DatahubConfigDto $config
     */
    public function __construct(DatahubConfigDto $config)
    {
        parent::__construct(
            $config->accessId,
            $config->accessKey,
            $config->endpoint,
            $config->projectId,
            $config->topicName
        );
    }

    /**
     * 创建 Topic。
     *
     * 示例：
     * ```php
     * $recordSchema = [
     *     'fields' => [
     *         ['name' => 'username', 'type' => 'STRING', 'comment' => '用户名'],
     *         ['name' => 'sex', 'type' => 'TINYINT', 'comment' => '性别'],
     *     ],
     * ];
     * $datahub->createTopic(
     *     'bing_test',
     *     1,
     *     1,
     *     DatahubConst::RecordTypeTuple,
     *     $recordSchema,
     *     '测试 topic'
     * );
     * ```
     *
     * @param string $topicName Topic 名称
     * @param int $shardCount 初始 Shard 数目
     * @param int $lifecycle 生命周期（天）
     * @param string $recordType {@see DatahubConst::RecordTypeTuple} / {@see DatahubConst::RecordTypeBlob}
     * @param array $recordSchema Tuple 时的字段结构
     * @param string $comment 描述
     * @return void
     * @throws DatahubException
     */
    public function createTopic(
        string $topicName,
        int $shardCount,
        int $lifecycle,
        string $recordType,
        array $recordSchema,
        string $comment
    ) {
        $uri = "/projects/{$this->projectId}/topics/{$topicName}";

        $params = [
            'Action' => 'create',
            'ShardCount' => $shardCount,
            'Lifecycle' => $lifecycle,
            'Comment' => $comment,
        ];

        if ($recordType == DatahubConst::RecordTypeTuple) {
            $params['RecordType'] = $recordType;
            $params['RecordSchema'] = json_encode($recordSchema, JSON_UNESCAPED_UNICODE);
        } elseif ($recordType == DatahubConst::RecordTypeBlob) {
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
     * 删除 Topic（不可恢复，请谨慎调用）。
     *
     * @param string $topicName Topic 名称
     * @return void
     */
    public function deleteTopic(string $topicName)
    {
        $uri = "/projects/{$this->projectId}/topics/$topicName";
        $this->delete($uri);
    }

    /**
     * 为当前配置的 Topic 创建订阅，返回含 SubId 等信息的响应。
     *
     * @param string $description 订阅备注
     * @return mixed
     * @throws DatahubException
     */
    public function createSubscription(string $description)
    {
        $uri = "/projects/{$this->projectId}/topics/{$this->topicName}/subscriptions";

        $params = [
            'Action' => 'create',
            'Comment' => $description,
        ];

        $result = $this->post($uri, $params);
        $this->errorHandle($uri, $result, $params);

        return $result;
    }
}
