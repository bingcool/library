<?php

declare(strict_types=1);

namespace Swoolefy\Library\PubSub;

use Swoolefy\Library\PubSub\Exception\InvalidSubscriptionException;
use Swoolefy\Library\PubSub\Exception\PubSubException;
use Swoolefy\Library\PubSub\Exception\SubscriptionConflictException;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * PubSub 抽象层：订阅连接的「所有权」与「消息派发策略」在此收口，驱动只做协议适配。
 *
 * 所有权约束：
 * - 注入的 Redis 连接被订阅循环独占，回调不再接收连接对象，只拿到渠道/模式与消息体，
 *   业务需要 Redis 时必须使用另一个连接；
 * - 退订只能由运行订阅循环的执行上下文发起，跨协程请求停止请用 {@see stop()}；
 * - `publish()` 需要另一个连接，订阅进行中在同一连接上调用直接抛异常。
 *
 * 派发策略由 `dispatch` 选项决定，任何回调失败都计入 {@see stats()} 的 `failed`，不静默丢消息。
 *
 * 协程内使用的前置条件：Swoole 的 stream hook 只作用于 hook 生效后建立的 socket，
 * 因此 Redis 连接必须在协程内创建；在协程外建连再进协程订阅，阻塞读会占死事件循环（静默死锁）。
 * hook 未开启时 {@see openSubscription()} 会直接拒绝， swoolefy 运行时的 hook_flags 恒为 SWOOLE_HOOK_ALL。
 */
abstract class AbstractPubSub
{
    public const DISPATCH_INLINE = 'inline';

    public const DISPATCH_COROUTINE = 'coroutine';

    /**
     * @var RedisConnection
     */
    protected $redis;

    /**
     * @var array{dispatch: string, max_concurrency: int, on_error: callable|null, coroutine_launcher: callable|null}
     */
    protected array $options;

    /** @var array<string, true> */
    private array $channels = [];

    /** @var array<string, true> */
    private array $patterns = [];

    /** @var callable|null */
    private $handler = null;

    /** @var \Swoole\Coroutine\Channel|null 协程派发用的并发令牌，容量即 max_concurrency */
    private $tokens = null;

    /** 订阅循环所在协程 cid，非协程为 -1 */
    private int $loopCid = -1;

    private bool $running = false;

    /** 跨上下文停止请求：本类不碰 socket，由循环读到下一条消息后自行退订 */
    private bool $stopRequested = false;

    /** @var array<string, int> */
    private array $counters = [
        'received' => 0,
        'dispatched' => 0,
        'completed' => 0,
        'failed' => 0,
        'in_flight' => 0,
    ];

    /** 协程派发里捕获的异常，交回循环协程重新抛出，保证失败对调用方可见 */
    private ?\Throwable $loopError = null;

    /**
     * @param array{dispatch?: string, max_concurrency?: int, on_error?: callable|null, coroutine_launcher?: callable|null} $options
     */
    public function __construct(RedisConnection $redis, array $options = [])
    {
        $this->redis = $redis;
        $this->options = $this->normalizeOptions($options);
    }

    /**
     * 订阅渠道并阻塞运行订阅循环。
     *
     * 回调签名 `fn(string $channel, string $payload): mixed`，返回 `false` 停止循环（内联派发时生效）。
     */
    final public function subscribe(array $channels, callable $handler): void
    {
        $this->openSubscription($this->normalizeNames($channels, 'subscribe'), $handler, false);
    }

    /**
     * 订阅模式并阻塞运行订阅循环。
     *
     * 回调签名 `fn(string $pattern, string $channel, string $payload): mixed`。
     */
    final public function psubscribe(array $patterns, callable $handler): void
    {
        $this->openSubscription($this->normalizeNames($patterns, 'psubscribe'), $handler, true);
    }

    /**
     * 退订渠道。必须在订阅循环所在上下文内调用（循环未运行时可直接调用）。
     */
    final public function unsubscribe(array $channels): void
    {
        $names = $this->normalizeNames($channels, 'unsubscribe');
        $this->guardSocketContext('unsubscribe');
        if ($this->channels === []) {
            throw new InvalidSubscriptionException('当前没有活动渠道订阅，unsubscribe() 无效');
        }
        $this->doUnsubscribe($names, false);
    }

    /**
     * 退订模式。必须在订阅循环所在上下文内调用（循环未运行时可直接调用）。
     */
    final public function punsubscribe(array $patterns): void
    {
        $names = $this->normalizeNames($patterns, 'punsubscribe');
        $this->guardSocketContext('punsubscribe');
        if ($this->patterns === []) {
            throw new InvalidSubscriptionException('当前没有活动模式订阅，punsubscribe() 无效');
        }
        $this->doUnsubscribe($names, true);
    }

    /**
     * @return int 收到该消息的订阅端数量
     * @throws SubscriptionConflictException 订阅进行中，或连接被其它订阅占用
     */
    final public function publish(string $channel, string $message): int
    {
        $this->normalizeNames([$channel], 'publish');
        if ($this->isSubscribed()) {
            throw new SubscriptionConflictException(
                '连接正处于订阅态，publish() 需要另一个连接（Redis 服务端只允许 (P|S)SUBSCRIBE/(P|S)UNSUBSCRIBE/PING/QUIT/RESET）'
            );
        }

        try {
            $result = $this->doPublish($channel, $message);
        } catch (\Throwable $e) {
            if ($this->isSubscribedContextError($e->getMessage())) {
                throw new SubscriptionConflictException('连接已被其它订阅占用，publish() 需要另一个连接', 0, $e);
            }

            throw $e;
        }

        if ($result === false) {
            throw new PubSubException(sprintf('publish() 到渠道 "%s" 失败', $channel));
        }

        return (int) $result;
    }

    /**
     * 请求停止订阅循环，任意上下文可调、不触碰 socket。
     *
     * 循环在下一次读到消息时自行退订；空闲无消息时不会立即返回。
     */
    final public function stop(): void
    {
        $this->stopRequested = true;
    }

    final public function isRunning(): bool
    {
        return $this->running;
    }

    final public function isSubscribed(): bool
    {
        return $this->channels !== [] || $this->patterns !== [];
    }

    /**
     * @return string[]
     */
    final public function subscribedChannels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * @return string[]
     */
    final public function subscribedPatterns(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * @return array{received: int, dispatched: int, completed: int, failed: int, in_flight: int, running: bool, channels: string[], patterns: string[]}
     */
    final public function stats(): array
    {
        return $this->counters + [
            'running' => $this->running,
            'channels' => array_keys($this->channels),
            'patterns' => array_keys($this->patterns),
        ];
    }

    final public function getRedis(): RedisConnection
    {
        return $this->redis;
    }

    /**
     * 驱动侧每条消息的唯一入口：计数、派发、决定循环是否继续。
     *
     * @return bool false 表示驱动应立刻退订并结束循环
     */
    final protected function handleMessage(string $channel, string $payload, ?string $pattern = null): bool
    {
        $this->counters['received']++;
        if ($this->stopRequested) {
            return false;
        }

        $handler = $this->handler;
        if ($handler === null) {
            return false;
        }

        $args = $pattern === null ? [$channel, $payload] : [$pattern, $channel, $payload];
        $context = ['channel' => $channel, 'pattern' => $pattern, 'payload' => $payload];

        if ($this->options['dispatch'] === self::DISPATCH_COROUTINE) {
            $this->dispatchAsync($handler, $args, $context);

            return $this->loopError === null && !$this->stopRequested;
        }

        return $this->dispatchInline($handler, $args, $context);
    }

    final protected function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    /**
     * 当前执行上下文的协程 id，非协程返回 -1。
     */
    final protected function currentCid(): int
    {
        return class_exists(\Swoole\Coroutine::class) ? \Swoole\Coroutine::getCid() : -1;
    }

    /**
     * 记录驱动侧退订后的本地订阅集合，保证 stop/重订阅状态一致。
     *
     * @param string[] $names
     */
    final protected function forgetNames(array $names, bool $isPattern): void
    {
        foreach ($names as $name) {
            if ($isPattern) {
                unset($this->patterns[$name]);
            } else {
                unset($this->channels[$name]);
            }
        }
    }

    /**
     * 循环结束（含异常退出）后清理会话，使下一次 subscribe() 从零状态开始。
     */
    final protected function finishSession(): void
    {
        $this->running = false;
        $this->handler = null;
        $this->loopCid = -1;
        $this->stopRequested = false;
        $this->channels = [];
        $this->patterns = [];
    }

    /**
     * 循环协程向调用方重抛协程派发里捕获的异常。
     */
    final protected function takeLoopError(): ?\Throwable
    {
        $error = $this->loopError;
        $this->loopError = null;

        return $error;
    }

    private function openSubscription(array $names, callable $handler, bool $isPattern): void
    {
        if ($this->running) {
            throw new SubscriptionConflictException('订阅循环已在运行，请为该连接使用独立的 PubSub 实例');
        }

        if ($this->options['dispatch'] === self::DISPATCH_COROUTINE && $this->currentCid() < 0) {
            throw new InvalidSubscriptionException('dispatch=coroutine 只能在 Swoole 协程内调用 subscribe()/psubscribe()');
        }

        $this->guardCoroutineHooks();

        $this->handler = $handler;
        $this->stopRequested = false;
        $this->loopError = null;
        $this->loopCid = $this->currentCid();
        $this->running = true;
        foreach ($names as $name) {
            if ($isPattern) {
                $this->patterns[$name] = true;
            } else {
                $this->channels[$name] = true;
            }
        }

        try {
            $this->openLoop($names, $handler, $isPattern);
        } finally {
            $this->finishSession();
        }

        $error = $this->takeLoopError();
        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function dispatchInline(callable $handler, array $args, array $context): bool
    {
        $this->counters['dispatched']++;
        $this->counters['in_flight']++;

        try {
            $continue = call_user_func_array($handler, $args);
            $this->counters['completed']++;
        } catch (\Throwable $e) {
            $this->counters['failed']++;
            if ($this->options['on_error'] !== null) {
                call_user_func($this->options['on_error'], $e, $context);

                return true;
            }

            // 驱动可能吞掉从回调里抛出的异常（phpredis 会），因此只能记下错误并请求停止，
            // 由 openSubscription() 在循环结束后向调用方重抛。
            $this->loopError ??= $e;
            $this->stopRequested = true;

            return false;
        } finally {
            $this->counters['in_flight']--;
        }

        return $continue !== false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function dispatchAsync(callable $handler, array $args, array $context): void
    {
        if ($this->tokens === null) {
            $this->tokens = new \Swoole\Coroutine\Channel($this->options['max_concurrency']);
        }

        // 令牌用满时此处挂起，构成对订阅循环的天然背压
        $this->tokens->push(1);
        $this->counters['dispatched']++;
        $this->counters['in_flight']++;

        $launcher = $this->coroutineLauncher();
        $launcher(function () use ($handler, $args, $context): void {
            try {
                call_user_func_array($handler, $args);
                $this->counters['completed']++;
            } catch (\Throwable $e) {
                $this->counters['failed']++;
                if ($this->options['on_error'] === null) {
                    $this->loopError ??= $e;
                    $this->stopRequested = true;
                } else {
                    call_user_func($this->options['on_error'], $e, $context);
                }
            } finally {
                $this->counters['in_flight']--;
                $this->tokens->pop();
            }
        });
    }

    private function coroutineLauncher(): callable
    {
        $custom = $this->options['coroutine_launcher'];
        if ($custom !== null) {
            return $custom;
        }

        if (function_exists('goApp')) {
            return static function (callable $task): void {
                goApp($task);
            };
        }

        if (class_exists(\Swoole\Coroutine::class)) {
            return static function (callable $task): void {
                \Swoole\Coroutine::create($task);
            };
        }

        throw new InvalidSubscriptionException('dispatch=coroutine 需要 Swoole 协程环境，或显式配置 coroutine_launcher');
    }

    /**
     * 协程内跑阻塞订阅循环必须开启 TCP stream hook，否则两条驱动的阻塞读会钉死整个事件循环，
     * 同进程的发布协程永远得不到调度——表现为「进程活着但一条消息都收不到」的死锁。
     * swoolefy 的 hook_flags 恒为 SWOOLE_HOOK_ALL，这里只拦截 library 被独立用在协程里的场景。
     */
    private function guardCoroutineHooks(): void
    {
        if ($this->currentCid() < 0 || !class_exists(\Swoole\Runtime::class) || !defined('SWOOLE_HOOK_TCP')) {
            return;
        }

        if ((\Swoole\Runtime::getHookFlags() & SWOOLE_HOOK_TCP) !== SWOOLE_HOOK_TCP) {
            throw new InvalidSubscriptionException(
                '协程内订阅需要先执行 \\Swoole\\Runtime::enableCoroutine(SWOOLE_HOOK_ALL) 再建立 Redis 连接'
                . '（hook 只作用于之后创建的 socket），否则订阅循环的阻塞读会占死事件循环造成静默死锁；'
                . '未开启 hook 请改用非协程订阅'
            );
        }
    }

    private function guardSocketContext(string $api): void
    {
        if (!$this->running) {
            return;
        }

        $cid = $this->currentCid();
        if ($cid !== $this->loopCid) {
            throw new SubscriptionConflictException(sprintf(
                '%s() 必须由订阅循环所在上下文（cid=%d）发起，当前 cid=%d；跨上下文停止请用 stop()',
                $api,
                $this->loopCid,
                $cid
            ));
        }
    }

    /**
     * @return string[]
     */
    private function normalizeNames(array $names, string $api): array
    {
        $unique = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                throw new InvalidSubscriptionException(sprintf('%s() 的渠道/模式必须是非空字符串', $api));
            }
            $unique[$name] = true;
        }

        if ($unique === []) {
            throw new InvalidSubscriptionException(sprintf('%s() 至少需要一个渠道/模式', $api));
        }

        return array_keys($unique);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{dispatch: string, max_concurrency: int, on_error: callable|null, coroutine_launcher: callable|null}
     */
    private function normalizeOptions(array $options): array
    {
        $dispatch = (string) ($options['dispatch'] ?? self::DISPATCH_INLINE);
        if (!in_array($dispatch, [self::DISPATCH_INLINE, self::DISPATCH_COROUTINE], true)) {
            throw new InvalidSubscriptionException(sprintf(
                'dispatch 只支持 %s / %s，收到 "%s"',
                self::DISPATCH_INLINE,
                self::DISPATCH_COROUTINE,
                $dispatch
            ));
        }

        $maxConcurrency = (int) ($options['max_concurrency'] ?? 64);
        if ($maxConcurrency < 1 || $maxConcurrency > 65536) {
            throw new InvalidSubscriptionException('max_concurrency 必须在 1..65536 之间');
        }

        foreach (['on_error', 'coroutine_launcher'] as $callback) {
            if (isset($options[$callback]) && !is_callable($options[$callback])) {
                throw new InvalidSubscriptionException(sprintf('选项 %s 必须是 callable 或 null', $callback));
            }
        }

        return [
            'dispatch' => $dispatch,
            'max_concurrency' => $maxConcurrency,
            'on_error' => $options['on_error'] ?? null,
            'coroutine_launcher' => $options['coroutine_launcher'] ?? null,
        ];
    }

    private function isSubscribedContextError(string $message): bool
    {
        return str_contains(strtolower($message), "only (p|s)subscribe");
    }

    /**
     * 驱动侧发布：返回受影响订阅者数量，phpredis 失败时为 false。
     *
     * @return int|false
     */
    abstract protected function doPublish(string $channel, string $message);

    /**
     * 驱动侧阻塞循环：写入订阅命令并逐条调用 {@see handleMessage()}，
     * 返回 false 或 {@see isStopRequested()} 为真时结束循环并退订。
     *
     * @param string[] $names
     */
    abstract protected function openLoop(array $names, callable $handler, bool $isPattern): void;

    /**
     * 驱动侧退订，并保证退订确认被同一条连接读完，不留响应残留。
     *
     * @param string[] $names
     */
    abstract protected function doUnsubscribe(array $names, bool $isPattern): void;
}
