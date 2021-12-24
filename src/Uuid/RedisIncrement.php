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
     * set between 5s ~ 20s , default 15s
     * @var integer
     */
    protected $ttl;

    /**
     * @var array
     */
    protected $followConnections = [];

    /**
     * @var int
     */
    protected $retryTimes = 3;

    /**
     * @var bool
     */
    protected $isPredisDriver;

    /**
     * when master redis return empty|null value, report to record log
     *
     * @var \Closure
     */
    protected $errorReportClosure = null;

    /**
     * RedisIncr constructor.
     * @param RedisConnection $redis
     * @param string $incrKey
     * @param integer $ttl
     * @param array $followConnections
     * @param \Closure $errorReportClosure
     * @return void
     */
    public function __construct(
        RedisConnection $redis,
        string $incrKey,
        int $ttl = 15,
        array $followConnections = [],
        \Closure $errorReportClosure = null
    )
    {
        $this->redis = $redis;
        $this->incrKey = $incrKey;
        $this->ttl = $ttl;
        $this->followConnections = $followConnections;
        $this->errorReportClosure = $errorReportClosure;
        $this->isPredisDriver();
    }

    /**
     * @return int|null
     */
    public function getIncrId(?int $count = null)
    {
        if ($count <= 0) {
            $count = 1;
        }

        $usleepTime = 15 * 1000;
        do {
            $dataArr = $this->doHandle($this->redis, $count);
            if (!empty($dataArr)) {
                break;
            }
            usleep($usleepTime);
            --$this->retryTimes;
        } while ($this->retryTimes);

        if (empty($dataArr)) {
            if ($this->errorReportClosure instanceof \Closure) {
                try {
                    call_user_func($this->errorReportClosure);
                } catch (\Throwable $e) {

                }
            }

            if (count($this->followConnections) > 0) {
                foreach ($this->followConnections as $connection) {
                    $dataArr = $this->doHandle($connection, $count);
                    if (!empty($dataArr)) {
                        break;
                    }
                }
            }
        }


        if (empty($dataArr)) {
            return null;
        }

        list($prefixNumber, $incrId) = $dataArr;

        if (!isset($incrId) || !is_numeric($prefixNumber)) {
            return null;
        }

        $autoIncrId = (int)$prefixNumber + (int)$incrId;

        return $autoIncrId;
    }

    /**
     * @param RedisConnection $connection
     * @param int $count
     * @return mixed
     */
    protected function doHandle(RedisConnection $connection, int $count)
    {
        try {
            if ($this->isPredisDriver) {
                $dataArr = $connection->eval($this->getLuaScripts(), 1, ...[$this->incrKey, $step = $count ?? 1, $this->ttl]);
            } else {
                $dataArr = $connection->eval($this->getLuaScripts(), [$this->incrKey, $step = $count ?? 1, $this->ttl], 1);
            }
        } catch (\Throwable $e) {

        }
        return $dataArr ?? [];
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
        if ($this->redis instanceof \Common\Library\Cache\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }


}

