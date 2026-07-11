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

/**
 * DataHub 客户端连接配置（AccessKey + 项目/Topic）。
 *
 * 由业务侧赋值后传入 DatahubTool / AbstractBaseDatahub 子类构造函数。
 */
class DatahubConfigDto
{
    /** @var string AccessKey ID */
    public $accessId;

    /** @var string AccessKey Secret */
    public $accessKey;

    /** @var string DataHub Endpoint，如 https://dh-cn-hangzhou.aliyuncs.com */
    public $endpoint;

    /** @var string Project 名称 */
    public $projectId;

    /** @var string Topic 名称 */
    public $topicName;

    /**
     * 转为关联数组（便于日志或序列化）。
     *
     * @return array
     */
    public function toArray()
    {
        return (array) $this;
    }
}
