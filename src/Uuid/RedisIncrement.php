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

use Common\Library\Exception\UuidException;

class RedisIncrement
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $incrKey;

    /**
     * @var bool
     */
    protected $isPredisDriver = false;

    /**
     * RedisIncr constructor.
     * @param $redis
     * @param $incrKey
     * @Param $isPredisDriver
     */
    public function __construct($redis, string $incrKey, ?bool $isPredisDriver = null)
    {
        $this->redis = $redis;
        $this->incrKey = $incrKey;
        $this->isPredisDriver($isPredisDriver);
    }

    /**
     * @param bool|null $isPredisDriver
     * @throws UuidException
     */
    public function isPredisDriver(?bool $isPredisDriver = null)
    {
        if(!is_object($this->redis))
        {
            throw new UuidException(sprintf('%s::%s params of redis must be a object',__CLASS__,__FUNCTION__));
        }

        if(is_null($isPredisDriver))
        {
            if((class_exists('Predis\\Client') && $this->redis instanceof \Predis\Client)
                || (class_exists('Common\Library\Cache\Predis') && $this->redis instanceof \Common\Library\Cache\Predis))
            {
                $this->isPredisDriver = true;
            }
        }else
        {
            $this->isPredisDriver = $isPredisDriver;
        }
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
        $dataArr = $this->doHandle($count);

        if(empty($dataArr))
        {
            usleep(15*1000);
            $dataArr = $this->doHandle($count);
            if(empty($dataArr))
            {
                return null;
            }
        }
        list($prefixNumber, $incrId) = $dataArr;
        if(!isset($incrId) || !is_numeric($prefixNumber))
        {
            return null;
        }

        $autoIncrId = (int)$prefixNumber + $incrId;

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

}

