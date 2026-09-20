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

namespace Swoolefy\Library\Redis;

/**
 * Redis / Predis / RedisCluster 的公共连接层。
 *
 * 三套 Driver 的 `__call` 都委托 {@see callWithRetry()}：
 * 先执行原生命令 → 仅连接异常才重连 → 再问 RedisRetryPolicy 是否 replay。
 * 写命令、未知命令、MULTI 中的任何命令：愈合连接后抛出**第一次**异常，不二次执行。
 */
class RedisConnection
{

    /**
     * @var mixed 原生客户端：PHPRedis `\Redis`、Predis Client 或由子类持有的 Cluster
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
     * reconnect 时需要重新 AUTH。PHPRedis 的 auth() 会写入；Predis 也可能走 __call('auth')。
     *
     * @var string|null
     */
    protected $password;

    /**
     * 业务调用 SELECT 后记录的 db index。
     * PHPRedis 重连会回到 db 0，若不恢复，白名单里的 GET 也会读错库。
     * null 表示从未 SELECT，Predis 则沿用 parameters.database。
     *
     * @var int|null
     */
    protected $selectedDatabase;

    /**
     * MULTI / PIPELINE 一旦成功，后续 __call 只是入队。
     * 此时断线后若 replay 入队命令，会在新连接上变成独立执行。
     *
     * @var bool
     */
    protected $inMultiOrPipeline = false;

    /**
     * WATCH 成功后，后续写命令依赖乐观锁上下文；重连会丢失 WATCH，禁止 replay。
     *
     * @var bool
     */
    protected $watching = false;

    /**
     * @var RedisRetryPolicy|null
     */
    protected $retryPolicy;

    /**
     * int
     */
    const MAX_SPEND_LOG_NUM = 50;

    public function __construct()
    {
        $this->retryPolicy = new RedisRetryPolicy();
    }

    /**
     * @return RedisConnection
     */
    public function getConnection()
    {
        return $this;
    }

    /**
     * @param array $options enabled / max_times / delay / commands / extra_commands
     * @return $this
     */
    public function setRetryOptions(array $options)
    {
        $this->getRetryPolicy()->setOptions($options);
        return $this;
    }

    /**
     * 懒加载，兼容子类构造函数未调用 parent::__construct() 的测试桩。
     *
     * @return RedisRetryPolicy
     */
    public function getRetryPolicy(): RedisRetryPolicy
    {
        if (!$this->retryPolicy instanceof RedisRetryPolicy) {
            $this->retryPolicy = new RedisRetryPolicy();
        }

        return $this->retryPolicy;
    }

    /**
     * 成功路径可记方法名和参数（调试用）。
     * Retry 路径禁止走这里，避免把 value / token 打进日志。
     *
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
     * Retry 路径禁止写入命令参数，避免敏感数据进入日志。
     *
     * @param string $message
     */
    protected function logRetryEvent(string $message): void
    {
        if (count($this->lastLogs) > $this->spendLogNum) {
            $this->lastLogs = [];
        }
        $this->lastLogs[] = json_encode([
            'time' => date('Y-m-d, H:i:s'),
            'msg' => $message,
        ], JSON_UNESCAPED_UNICODE);
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
     * 协程内用 Swoole sleep，避免阻塞 Worker；非协程（CLI 单测 / FPM）退回 usleep。
     * delay<=0 时直接返回，便于单测把重连等待关掉。
     *
     * @param float $time
     */
    protected function sleep(float $time = 0.5)
    {
        if ($time <= 0) {
            return;
        }

        if (extension_loaded('swoole') && class_exists('\\Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine\System::sleep($time);
            return;
        }

        usleep((int)round($time * 1000000));
    }

    /**
     * 三套 Driver 共用的「执行 → 连接异常则重连 → 按白名单决定是否 replay」循环。
     *
     * 流程：
     * 1. 调用 $invoke 打到原生客户端（必须是 $this->redis->method，不能再进 __call，否则死递归）
     * 2. 非连接异常（WRONGTYPE 等）立即抛出，不 sleep、不重连
     * 3. 连接异常：先 reconnect 愈合连接（连接池不能把死连接还回去）
     * 4. 不在白名单 / 处于 MULTI / 超过 max_times：抛出**本次**捕获的异常，不二次执行
     * 5. 允许 retry：循环再 invoke 一次。第二次仍失败则抛第二次异常，不再第三次
     *
     * 非 retry 路径必须抛原连接异常，不能吞掉：业务需要知道写操作结果不确定。
     * 重连失败则包装 RuntimeException，previous 保留第一次连接异常。
     *
     * @param string $method
     * @param array $arguments
     * @param callable $invoke function(string $method, array $arguments): mixed
     * @return mixed
     * @throws \Throwable
     */
    protected function callWithRetry(string $method, array $arguments, callable $invoke)
    {
        $command = RedisRetryPolicy::resolveCommand($method, $arguments);
        $attempt = 0;
        $maxTimes = $this->getRetryPolicy()->getMaxTimes();

        while (true) {
            $attempt++;
            try {
                $result = $invoke($method, $arguments);
                $this->rememberSessionState($method, $arguments);
                if ($attempt === 1) {
                    $this->log($method, $arguments);
                }
                return $result;
            } catch (\Throwable $exception) {
                if (!$this->isRedisConnectionException($exception)) {
                    throw $exception;
                }

                $inTransactionalContext = $this->inTransactionalContext();

                // 先愈合连接，再决定是否 replay。写命令也会走到这里。
                try {
                    $this->reconnectAfterFailure();
                } catch (\Throwable $reconnectException) {
                    throw new \RuntimeException(
                        'Redis reconnect failed: ' . $reconnectException->getMessage(),
                        (int)$reconnectException->getCode(),
                        $exception
                    );
                }

                // 新连接不在 MULTI/WATCH 中。是否 replay 用重连前捕获的 $inTransactionalContext，
                // 不能先清标志再读属性，否则会把「MULTI 中的 GET」误判成可 replay。
                $this->resetTransactionalContext();

                $shouldReplay = !$inTransactionalContext
                    && $this->getRetryPolicy()->shouldRetry($command)
                    && $attempt <= $maxTimes;

                if (!$shouldReplay) {
                    $reason = 'non_retryable';
                    if ($inTransactionalContext) {
                        $reason = 'in_multi';
                    } elseif ($attempt > $maxTimes) {
                        $reason = 'retry_exhausted';
                    } elseif (!$this->getRetryPolicy()->isEnabled()) {
                        $reason = 'disabled';
                    }
                    $this->logRetryEvent("redis retry skipped: command={$command} reason={$reason}");
                    throw $exception;
                }

                $this->logRetryEvent('redis retry: command=' . $command . ' attempt=' . ($attempt + 1) . ' reason=connection_error');
            }
        }
    }

    /**
     * PHPRedis / RedisCluster 默认实现。Predis 覆盖为 {@see RedisRetryPolicy::isPredisConnectionException()}。
     *
     * @param \Throwable $exception
     * @return bool
     */
    protected function isRedisConnectionException(\Throwable $exception): bool
    {
        return RedisRetryPolicy::isPhpRedisConnectionException($exception);
    }

    /**
     * 当前连接是否处于 MULTI/PIPELINE 或 WATCH。为 true 时任何命令都禁止 replay。
     *
     * @return bool
     */
    protected function inTransactionalContext(): bool
    {
        return $this->inMultiOrPipeline || $this->watching;
    }

    /**
     * 重连后新连接是干净的，MULTI/WATCH 标志必须清掉。
     */
    protected function resetTransactionalContext(): void
    {
        $this->inMultiOrPipeline = false;
        $this->watching = false;
    }

    /**
     * 命令成功后记录会话状态，供断线重连恢复。
     *
     * SELECT：必须记住 db，否则重连后 GET 打到 db 0。
     * MULTI/PIPELINE/WATCH：记住后，后续连接异常禁止 replay。
     * AUTH：Predis 走 __call，也要记下密码。PHPRedis 的 auth() 是实方法，在子类里单独赋值。
     *
     * @param string $method
     * @param array $arguments
     */
    protected function rememberSessionState(string $method, array $arguments): void
    {
        $command = RedisRetryPolicy::resolveCommand($method, $arguments);
        switch ($command) {
            case 'SELECT':
                if (isset($arguments[0]) && is_numeric($arguments[0])) {
                    $this->selectedDatabase = (int)$arguments[0];
                }
                break;
            case 'MULTI':
            case 'PIPELINE':
                $this->inMultiOrPipeline = true;
                break;
            case 'WATCH':
                $this->watching = true;
                break;
            case 'EXEC':
            case 'DISCARD':
                $this->inMultiOrPipeline = false;
                $this->watching = false;
                break;
            case 'UNWATCH':
                $this->watching = false;
                break;
            case 'AUTH':
                if (isset($arguments[0]) && (is_string($arguments[0]) || is_int($arguments[0]))) {
                    $this->password = (string)$arguments[0];
                }
                break;
        }
    }

    /**
     * 仅连接异常路径调用：可选 delay 后关闭死连接并重建。
     * 业务错误不得进入这里，避免 WRONGTYPE 也被 sleep 0.5 秒。
     *
     * @throws \Throwable
     */
    protected function reconnectAfterFailure(): void
    {
        $delay = $this->getRetryPolicy()->getDelay();
        if ($delay > 0) {
            $this->sleep($delay);
        }
        $this->closeNativeConnection();
        $this->reConnect();
    }

    /**
     * 关闭可能已死的原生连接。close() 自身也可能抛异常，必须吞掉再重建。
     */
    protected function closeNativeConnection(): void
    {
    }

    /**
     * 子类重建客户端，并恢复 AUTH / SELECT。
     *
     * @throws \Throwable
     */
    protected function reConnect()
    {
    }

    /**
     * 断线重连后恢复 SELECT db。AUTH 由各 Driver 在 reConnect() 里处理。
     *
     * 必须直接打原生 select，不能 $this->select()：否则会再进 __call / callWithRetry。
     *
     * @param callable $select function(int $database): void
     */
    protected function restoreSelectedDatabase(callable $select): void
    {
        if ($this->selectedDatabase === null) {
            return;
        }
        $select($this->selectedDatabase);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->lastLogs = [];
    }

}
