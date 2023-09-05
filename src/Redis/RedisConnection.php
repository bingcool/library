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

namespace Common\Library\Redis;

/**
 * Class RedisConnection
 * @package Common\Library\Redis
 */
class RedisConnection
{

    /**
     * @var mixed
     */
    protected $redis;

    /**
     * @var array
     */
    protected $lastLogs = [];

    /**
     * @var int
     */
    protected $spendLogNum = 20;

    /**
     * int
     */
    const MAX_SPEND_LOG_NUM = 50;

    /**
     * @return RedisConnection
     */
    public function getConnection()
    {
        return $this;
    }

    /**
     * @param string $method
     * @param mixed $arguments
     * @param string $errorMsg
     */
    protected function log(string $method, $arguments, string $errorMsg = 'ok')
    {
        if (count($this->lastLogs) > $this->spendLogNum) {
            $this->lastLogs = [];
        }
        $this->lastLogs[] = json_encode(['time' => date('Y-m-d, H:i:s'), 'method' => $method, 'args' => $arguments, 'msg' => $errorMsg], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array
     */
    public function getLastLogs()
    {
        return array_map(function ($item) {
            return json_decode($item, true) ?? [];
        }, $this->lastLogs);
    }

    /**
     * @param int $logNum
     */
    public function setLimitLogNum(int $spendLogNum)
    {
        if ($spendLogNum > static::MAX_SPEND_LOG_NUM) {
            $spendLogNum = static::MAX_SPEND_LOG_NUM;
        }
        $this->spendLogNum = $spendLogNum;
    }

    /**
     * @param float $time
     */
    protected function sleep(float $time = 0.5)
    {
        \Swoole\Coroutine\System::sleep($time);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->lastLogs = [];
    }

}