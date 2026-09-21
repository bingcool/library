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

namespace Swoolefy\Library\Uuid;

use Swoolefy\Library\Redis\RedisConnection;

class UuidIncrement
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
     * 单次 generateId 的默认重试次数（配置）。循环内只递减局部变量，避免污染实例。
     *
     * @var int
     */
    protected $retryTimes = 3;

    /**
     * @var bool
     */
    protected $isPredisDriver;

    /**
     * @var array
     */
    protected $poolIds = [];

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
     * @param \Closure|null $errorReportClosure
     * @return void
     */
    public function __construct(
        RedisConnection $redis,
        string $incrKey,
        int $ttl = 15,
        array $followConnections = [],
        ?\Closure $errorReportClosure = null
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
     * pre GenerateId
     *
     * @param int $count
     * @return bool
     */
    public function preBatchGenerateIds(int $count)
    {
        if($count >= 20000) {
            $count = 20000;
        }else if($count <= 0) {
            $count = 10;
        }
        $maxId = $this->generateId($count);
        if ($maxId === null) {
            return true;
        }
        $minId = $maxId - $count;
        if($minId > 0) {
            for($i=0; $i<$count; $i++) {
                $this->poolIds[] = $minId+$i;
            }
        }
        return true;
    }

    /**
     * 向 Redis 申请一段自增 ID。失败返回 null；调用方不得把 null 代入 $maxId - $count。
     *
     * @param int|null $count
     * @return int|null
     */
    protected function generateId(?int $count = null)
    {
        if ($count <= 0) {
            $count = 1;
        }

        $usleepTime = 15 * 1000;
        $retryTimes = $this->retryTimes;
        do {
            $dataArr = $this->doHandle($this->redis, $count);
            if (!empty($dataArr)) {
                break;
            }
            $this->waitBeforeRetry($usleepTime);
            --$retryTimes;
        } while ($retryTimes);

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
     * @return int|null
     */
    public function getIncrId()
    {
        if($this->poolIds) {
            return array_shift($this->poolIds);
        }
        return $this->generateId(1);
    }

    /**
     * @param int $microseconds
     */
    protected function waitBeforeRetry(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }
        usleep($microseconds);
    }

    /**
     * @param RedisConnection $connection
     * @param int $count
     * @return mixed
     */
    protected function doHandle(RedisConnection $connection, int $count)
    {
        if ($this->isPredisDriver) {
            $dataArr = $connection->eval($this->getLuaScripts(), 1, ...[$this->incrKey, $step = $count ?? 1, $this->ttl]);
        } else {
            $dataArr = $connection->eval($this->getLuaScripts(), [$this->incrKey, $step = $count ?? 1, $this->ttl], 1);
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
        if ($this->redis instanceof \Swoolefy\Library\Redis\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }


}

