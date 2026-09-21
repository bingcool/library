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

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Library\Redis\RedisConnection;

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
     * 单次 generateId 的默认重试次数（配置，禁止在循环里递减本属性）。
     * 直接 --$this->retryTimes 会污染被容器复用的实例，耗尽后 --0 变成 -1 可能死循环。
     *
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
     * @param \Closure|null $errorReportClosure
     * @return void
     */
    public function __construct(
        RedisConnection $redis,
        string          $incrKey,
        int             $ttl = 15,
        array           $followConnections = [],
        ?\Closure        $errorReportClosure = null
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
     * registerTickPreBatchGenerateIds 注册定时器产生GenerateId
     *
     * @param int $timeMs 定时预产生uuid,单位：毫秒,建议设置为1000~2000毫秒
     * @param int $poolSize 预产生uuid数量,建议100~1000之间数值
     * @return bool
     */
    public function registerTickPreBatchGenerateIds(int $timeMs, int $poolSize)
    {
        if (!(self::$poolIdsQueue instanceof Channel)) {
            self::$poolIdsQueue = new Channel($poolSize);
        }

        if ($this->startTime > 0) {
            return false;
        }

        $this->startTime  = time();
        if ($poolSize <= 1) {
            $poolSize = 1;
        }

        if ($timeMs <= 1000) {
            $timeMs = 1000;
        }

        if ($timeMs > 5000) {
            $timeMs = 5000;
        }

        $timeOutSecond = $timeMs / 1000;

        goTick($timeMs, function () use($poolSize, $timeOutSecond) {
            try {
                if(time() >= $this->startTime + $timeOutSecond * 3) {
                    $this->startTime = time();
                    if(self::$poolIdsQueue->length() > 0) {
                        while (self::$poolIdsQueue->pop(0.02)) {

                        }
                    }
                }

                $maxId = $this->generateId($poolSize);
                if ($maxId === null) {
                    return;
                }
                $minId = $maxId - $poolSize;
                if ($minId > 0) {
                    for ($i = 0; $i < $poolSize; $i++) {
                        self::$poolIdsQueue->push($minId + $i, 0.05);
                    }
                }
            }catch (\Throwable $throwable){

            }
        }, true);

        return true;
    }

    /**
     * 向 Redis 申请一段自增 ID。失败返回 null，调用方不得把 null 代入减法。
     *
     * @param int|null $count
     * @param RedisConnection|null $redis
     * @return int|null
     */
    protected function generateId(?int $count = null, ?RedisConnection $redis = null)
    {
        if ($count <= 0) {
            $count = 1;
        }

        $sleepTimeSecond = 0.15;
        $retryTimes = $this->retryTimes;
        do {
            $dataArr = $this->doHandle($redis ?? $this->redis, $count);
            if (!empty($dataArr)) {
                break;
            }
            $this->waitBeforeRetry($sleepTimeSecond);
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
     * 批量取号：Channel 有存货时先出队（能取多少取多少），不足再向 Redis 要一段。
     *
     * 必须先判断是否已满再 pop，否则会多消费 1 个 ID 并丢弃。
     * generateId() 失败返回 null 时直接返回已收集部分，禁止 $maxId - $remainNum。
     *
     * @param int $num
     * @return array
     */
    public function getIncrIds(int $num = 1): array
    {
        if ($num <= 0) {
            return [];
        }

        if (!(self::$poolIdsQueue instanceof Channel)) {
            self::$poolIdsQueue = new Channel(100);
        }

        $poolIds = [];
        if (self::$poolIdsQueue->length() > 0) {
            $popNum = 0;
            while ($popNum < $num) {
                $uuid = self::$poolIdsQueue->pop(0.05);
                if ($uuid === false) {
                    break;
                }
                $popNum++;
                $poolIds[] = $uuid;
            }
        }

        $poolIds = array_unique($poolIds);
        $hasNum  = count($poolIds);
        if ($hasNum < $num) {
            $remainNum = $num - $hasNum;
            $maxId = $this->generateId($remainNum, $this->redis);
            if ($maxId === null) {
                return $poolIds;
            }
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
     * @return int|null 失败时返回 null（空列表不再 current([]) === false）
     */
    public function getOneId()
    {
        $poolIds = $this->getIncrIds(1);
        return $poolIds[0] ?? null;
    }

    /**
     * 重试间隔：协程内用 Coroutine::sleep，避免阻塞 Worker；非协程退回 usleep。
     *
     * @param float $seconds
     */
    protected function waitBeforeRetry(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (Coroutine::getCid() >= 0) {
            Coroutine::sleep($seconds);
            return;
        }
        usleep((int)round($seconds * 1000000));
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