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

use SplQueue;
use Swoole\Coroutine\Channel;
use Common\Library\Redis\RedisConnection;

class UuidManager
{
    /**
     * @var mixed
     */
    private static $instance;

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
     * @var int
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
     * @var Channel
     */
    protected static $poolIdsQueue;

    /**
     * when master redis return empty|null value, report to record log
     *
     * @var \Closure
     */
    protected $errorReportClosure = null;

    /**
     * @var int
     */
    protected $startTime;

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
        string          $incrKey,
        int             $ttl = 15,
        array           $followConnections = [],
        \Closure        $errorReportClosure = null
    )
    {
        $this->redis              = $redis;
        $this->incrKey            = $incrKey;
        $this->ttl                = $ttl;
        $this->followConnections  = $followConnections;
        $this->errorReportClosure = $errorReportClosure;
        $this->isPredisDriver();
    }

    /**
     * @param mixed ...$args
     * @return static
     */
    public static function getInstance(RedisConnection $redis, string $incrKey, ...$args)
    {
        return new static($redis, $incrKey, ...$args);
    }

    /**
     * @return Channel
     */
    public function getPoolIdsQueue()
    {
        return self::$poolIdsQueue;
    }

    /**
     * pre GenerateId
     *
     * @param float $timeOut
     * @param int $poolSize
     * @return bool
     */
    public function tickPreBatchGenerateIds(float $timeOut, int $poolSize)
    {
        if (!(self::$poolIdsQueue instanceof Channel)) {
            self::$poolIdsQueue = new Channel($poolSize);
        }

        $pushTickChannel  = new Channel(1);
        $this->startTime  = time();

        if($poolSize <= 1) {
            $poolSize = 1;
        }

        goApp(function () use($poolSize, $timeOut, $pushTickChannel) {
            // generateId
            while(!$pushTickChannel->pop($timeOut)) {
                try {
                    if(time() >= $this->startTime + $timeOut * 3) {
                        $this->startTime = time();
                        if(self::$poolIdsQueue->length() > 0) {
                            while (self::$poolIdsQueue->pop(0.02)) {

                            }
                        }
                    }

                    $maxId = $this->generateId($poolSize);
                    $minId = $maxId - $poolSize;
                    if ($minId > 0) {
                        for ($i = 0; $i < $poolSize; $i++) {
                            self::$poolIdsQueue->push($minId + $i, 0.05);
                        }
                    }
                }catch (\Throwable $throwable){

                }
            }
        });

        return true;
    }

    /**
     * generateId
     *
     * @param int|null $count
     * @param RedisConnection
     * @return int|null
     */
    protected function generateId(?int $count = null, ?RedisConnection $redis = null)
    {
        if ($count <= 0) {
            $count = 1;
        }

        $usleepTime = 15 * 1000;
        do {
            $dataArr = $this->doHandle($redis ?? $this->redis, $count);
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
     * @param RedisConnection $redis
     * @param int $num
     * @return array
     */
    public function getIncrIds(int $num = 1)
    {
        if (!(self::$poolIdsQueue instanceof Channel)) {
            self::$poolIdsQueue = new Channel(100);
        }

        $poolIds = [];
        if(self::$poolIdsQueue->length() > ($num + 1) ) {
            $popNum = 0;
            while ($uuid = self::$poolIdsQueue->pop(0.05)) {
                if ($popNum >= $num) {
                    break;
                }
                $popNum++;
                $poolIds[] = $uuid;
            }
        }

        $poolIds = array_unique($poolIds);
        $hasNum  = count($poolIds);
        if($hasNum < $num) {
            $remainNum = $num - $hasNum;
            $maxId = $this->generateId($remainNum, $this->redis);
            $minId = $maxId - $remainNum;
            if ($minId > 0) {
                for ($i = 0; $i < $remainNum; $i++) {
                    $poolIds[] = $minId + $i;
                }
            }
        }
        return $poolIds;
    }

    /**
     * @return int
     */
    public function getOneId()
    {
        $poolIds = $this->getIncrIds(1);
        return current($poolIds);
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
        if ($this->redis instanceof \Common\Library\Redis\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }

}