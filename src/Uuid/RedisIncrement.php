<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\Uuid;

use Common\Library\Cache\RedisConnection;

class RedisIncrement
{
    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * @var string
     */
    protected $incrKey;

    /**
     * @var int
     */
    protected $retryTimes = 3;

    /**
     * @var bool
     */
    protected $isPredisDriver;

    /**
     * RedisIncr constructor.
     * @param $redis
     * @param $incrKey
     * @Param $isPredisDriver
     */
    public function __construct(RedisConnection $redis, string $incrKey)
    {
        $this->redis = $redis;
        $this->incrKey = $incrKey;
        $this->isPredisDriver();
    }


    /**
     * @return null
     */
    public function getIncrId(?int $count = null)
    {
        if($count <= 0)
        {
            $count = 1;
        }

        $dataArr = [];
        do {
            $dataArr = $this->doHandle($count);
            if(!empty($dataArr))
            {
                break;
            }
            usleep(15*1000);
            --$this->retryTimes;
        }while($this->retryTimes);

        if(empty($dataArr))
        {
            return null;
        }

        list($prefixNumber, $incrId) = $dataArr;

        if(!isset($incrId) || !is_numeric($prefixNumber))
        {
            return null;
        }

        $autoIncrId = (int)$prefixNumber + (int)$incrId;

        return $autoIncrId;
    }

    /**
     * @param $count
     * @return mixed
     */
    protected function doHandle($count)
    {
        if($this->isPredisDriver)
        {
            $dataArr = $this->redis->eval($this->getLuaScripts(), 1, ...[$this->incrKey, $step = $count ?? 1]);
        }else
        {
            $dataArr = $this->redis->eval($this->getLuaScripts(), [$this->incrKey, $step = $count ?? 1], 1);
        }

        return $dataArr;
    }

    /**
     * @return string
     */
    protected function getLuaScripts()
    {
        return LuaScripts::getUuidLuaScript();
    }

    /**
     * @return bool
     */
    public function isPredisDriver()
    {
        if($this->redis instanceof \Common\Library\Cache\Predis)
        {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }


}

