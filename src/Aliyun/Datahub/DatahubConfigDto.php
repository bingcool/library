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

class DatahubConfigDto
{
    public $accessId;

    public $accessKey;

    public $endpoint;

    public $projectId;

    public $topicName;

    /**
     * toArray
     */
    public function toArray()
    {
        return (array)$this;
    }
}