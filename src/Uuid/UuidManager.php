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

use Swoole\Coroutine\Channel;
use Common\Library\Cache\RedisConnection;
use function foo\func;

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
     * @var Channel
     */
    protected $channel;

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
    public static function getInstance(...$args)
    {
        if (!isset(self::$instance)) {
            self::$instance = new static(...$args);
        }
        return self::$instance;
    }

    /**
     * @return Channel
     */
    public function getChannel()
    {
        return $this->channel;
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
        $this->channel    = new Channel($poolSize);
        $pushTickChannel  = new Channel(1);
        $this->startTime  = time();
        \Swoole\Coroutine::create(function () use($poolSize, $timeOut, $pushTickChannel) {
            // generateId
            while(!$pushTickChannel->pop($timeOut)) {
                try {

                    if(time() >= $this->startTime + $timeOut * 3) {
                        $this->startTime = time();
                        if($this->channel->length() > 0) {
                            while ($this->channel->pop(0.05)) {
                                continue;
                            }
                        }
                    }

                    $maxId = $this->generateId($poolSize);
                    $minId = $maxId - $poolSize;
                    if ($minId > 0) {
                        for ($i = 0; $i < $poolSize; $i++) {
                            $value = $this->channel->push($minId + $i, 2);
                            if(empty($value)) {
                                break;
                            }
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
     * @param float $timeOut
     * @return array
     */
    public function getIncrIds(?RedisConnection $redis, int $num = 1, float $timeOut = 0.5)
    {
        $poolIds = [];
        if($this->channel->length() > $num+1) {
            for ($i = 0; $i < $num; $i++) {
                $unId = $this->channel->pop($timeOut);
                if(empty($id)) {
                    break;
                }
                $poolIds[] = $unId;
            }
        }

        $poolIds = array_unique($poolIds);
        $hasNum  = count($poolIds);
        if($hasNum < $num) {
            $remainNum = $num - $hasNum;
            $maxId = $this->generateId($remainNum, $redis);
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
        if ($this->redis instanceof \Common\Library\Cache\Predis) {
            $this->isPredisDriver = true;
        }
        return $this->isPredisDriver;
    }

}