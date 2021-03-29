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

namespace Common\Library\Cache;

/**
 * Class RedisConnection
 * @package Common\Library\Cache
 */

class RedisConnection {

    /**
     * @var null
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
     * @param $method
     * @param $arguments
     * @param $errorMsg
     */
    protected function log($method, $arguments, $errorMsg = 'ok') {
        if(count($this->lastLogs) > $this->spendLogNum) {
            $this->lastLogs = [];
        }
        $this->lastLogs[] = json_encode(['time'=>date('Y-m-d, H:i:s'), 'method'=>$method, 'args'=>$arguments, 'msg'=>$errorMsg],JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array
     */
    public function getLastLogs() {
        return array_map(function ($item) {
            return json_decode($item, true) ?? [];
        }, $this->lastLogs);
    }

    /**
     * @param int $logNum
     */
    public function setLimitLogNum(int $spendLogNum) {
        //最大记录前50个操作即可，防止在循坏中大量创建
        if($spendLogNum > static::MAX_SPEND_LOG_NUM) {
            $spendLogNum = static::MAX_SPEND_LOG_NUM;
        }
        $this->spendLogNum = $spendLogNum;
    }

    /**
     * @param float $time
     */
    protected function sleep(float $time = 0.5) {
        if(class_exists('Swoole\Coroutine\System') && \Swoole\Coroutine::getCid() > 0)
        {
            \Swoole\Coroutine\System::sleep($time);
        }else{
            if($time < 1)
            {
                usleep($time * 1000000);
            }else
            {
                sleep(floor($time));
            }
        }
    }

    /**
     * __destruct
     */
    public function __destruct() {
        $this->lastLogs = [];
    }

}