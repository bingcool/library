<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\PubSub;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Consumer\PubSub\Consumer as PredisConsumer;
use Swoolefy\Library\PubSub\AbstractPubSub;
use Swoolefy\Library\PubSub\Exception\InvalidSubscriptionException;
use Swoolefy\Library\PubSub\Exception\PubSubException;
use Swoolefy\Library\PubSub\Exception\SubscriptionConflictException;
use Swoolefy\Library\PubSub\PredisPubSub;
use Swoolefy\Library\PubSub\RedisPubSub;
use Swoolefy\Library\Redis\Predis as PredisConnection;
use Swoolefy\Library\Redis\Redis as PhpRedisConnection;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * PubSub 回归测试，逐条覆盖审查报告 §8 的 R-01…R-16。
 *
 * 无 ext-redis / predis / Swoole 或 Redis 不可达时按 skip 处理，保证 CI 在无服务环境下不红灯。
 *
 * @see docs/library-6.x-PubSub-审查报告.md
 */
final class PubSubModuleTest extends TestCase
{
    /** 阻塞订阅循环的最长看门狗时间（秒），超时直接终止用例，避免整个套件挂死 */
    private const LOOP_WATCHDOG = 25;

    /** @var list<int> 需要清理的发布子进程 pid */
    private array $publisherPids = [];

    protected function tearDown(): void
    {
        foreach ($this->publisherPids as $pid) {
            posix_kill($pid, SIGKILL);
        }

        $this->publisherPids = [];
        pcntl_alarm(0);
        pcntl_async_signals(false);
        parent::tearDown();
    }

    public function testPredisConsumerClassIsTheOneImportedByTheDriver(): void
    {
        $this->assertTrue(class_exists(PredisConsumer::class), 'Predis 3.x 的 Consumer 真实命名空间必须可解析');
        $this->assertFalse(class_exists('Predis\PubSub\Consumer'), '旧报告里的 Predis\PubSub\Consumer 并不存在');

        foreach (['PredisPubSub.php', 'RedisPubSub.php', 'AbstractPubSub.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/PubSub/' . $file);
            preg_match_all('/^use\s+([^;\s]+);/m', $source, $matches);
            foreach ($matches[1] as $import) {
                if (str_starts_with($import, 'Swoolefy\\') || !str_contains($import, '\\')) {
                    continue;
                }

                $this->assertTrue(
                    class_exists($import) || interface_exists($import),
                    sprintf('%s 引用了不存在的类 %s', $file, $import)
                );
            }
        }
    }

    #[DataProvider('invalidNameProvider')]
    public function testEmptyAndNonStringNamesAreRejectedBeforeTouchingTheSocket(array $names, string $api): void
    {
        $pubSub = $this->pubSubWithoutServer('predis');

        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage($api);

        if ($api === 'unsubscribe' || $api === 'punsubscribe') {
            $pubSub->{$api}($names);

            return;
        }

        $pubSub->{$api}($names, static fn(): bool => false);
    }

    public static function invalidNameProvider(): array
    {
        return [
            'subscribe 空列表' => [[], 'subscribe'],
            'subscribe 空字符串' => [[''], 'subscribe'],
            'subscribe 非字符串' => [[123], 'subscribe'],
            'psubscribe 空列表' => [[], 'psubscribe'],
            'psubscribe 混合非字符串' => [['', null], 'psubscribe'],
            'unsubscribe 空列表' => [[], 'unsubscribe'],
            'punsubscribe 非字符串' => [[true], 'punsubscribe'],
        ];
    }

    public function testPublishRejectsEmptyChannel(): void
    {
        $pubSub = $this->pubSubWithoutServer('predis');

        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('publish');
        $pubSub->publish('', 'body');
    }

    public function testInvalidDispatchOptionsAreRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('dispatch');
        $this->pubSubWithoutServer('predis', ['dispatch' => 'thread']);
    }

    public function testInvalidMaxConcurrencyIsRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('max_concurrency');
        $this->pubSubWithoutServer('predis', ['dispatch' => 'coroutine', 'max_concurrency' => 0]);
    }

    public function testCoroutineDispatchOutsideCoroutineIsRejected(): void
    {
        $this->requireDriver('predis');
        $pubSub = $this->pubSubWithoutServer('predis', ['dispatch' => 'coroutine']);

        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('dispatch=coroutine');
        $pubSub->subscribe(['phpunit:co-only'], static fn(): bool => false);
    }

    public function testUnsubscribeWithoutActiveSessionIsRejected(): void
    {
        $this->requireDriver('predis');
        $pubSub = $this->pubSubWithoutServer('predis');

        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('没有活动渠道订阅');
        $pubSub->unsubscribe(['phpunit:none']);
    }

    #[DataProvider('driverProvider')]
    public function testEachDriverRequiresItsOwnConnectionType(string $driver): void
    {
        $wrong = $driver === 'phpredis' ? new PredisConnection(['scheme' => 'tcp']) : new PhpRedisConnection();

        $this->expectException(PubSubException::class);
        $this->expectExceptionMessage($driver === 'phpredis' ? 'RedisPubSub' : 'PredisPubSub');
        $this->pubSub($driver, $wrong);
    }

    #[DataProvider('driverProvider')]
    public function testPublishOnIdleConnectionReturnsReceiverCount(string $driver): void
    {
        $this->requireRedis($driver);
        $connection = $this->connection($driver);
        $channel = $this->channelName('idle');

        $this->assertSame(0, $this->pubSub($driver, $connection)->publish($channel, 'nobody-listening'));
    }

    // R-01 / R-11：非协程运行时端到端收发，回调只拿渠道与消息体
    #[DataProvider('driverProvider')]
    public function testInlineDispatchDeliversEveryMessageWithoutACoroutine(string $driver): void
    {
        $this->requireRedis($driver);
        $channel = $this->channelName('inline');
        $messages = ['m1', 'm2', 'm3'];
        $pubSub = $this->pubSub($driver, $this->connection($driver));
        $seen = [];
        $channels = [];
        $argCounts = [];

        $this->spawnPublisher($driver, $channel, $messages, 0.3);
        $this->armWatchdog($channel);
        $pubSub->subscribe([$channel], static function (...$args) use (&$seen, &$argCounts, &$channels, $messages): ?bool {
            [$channel, $payload] = $args;
            $seen[] = $payload;
            $channels[] = $channel;
            $argCounts[] = count($args);

            return $payload === $messages[count($messages) - 1] ? false : null;
        });

        $this->assertSame([2, 2, 2], $argCounts, '订阅回调必须只接收渠道与消息体');
        $this->assertSame($messages, $seen);
        $this->assertSame([$channel, $channel, $channel], $channels);
        $this->assertFalse($pubSub->isRunning());
        $this->assertFalse($pubSub->isSubscribed());
        $stats = $pubSub->stats();
        $this->assertSame(3, $stats['received']);
        $this->assertSame(3, $stats['completed']);
        $this->assertSame(0, $stats['failed']);
    }

    // R-11：两种驱动共用同一段回调代码，模式订阅参数个数一致
    #[DataProvider('driverProvider')]
    public function testPatternDispatchPassesPatternChannelPayload(string $driver): void
    {
        $this->requireRedis($driver);
        $suffix = $this->channelName('ptn');
        $pattern = $suffix . ':*';
        $channel = $suffix . ':leaf';
        $pubSub = $this->pubSub($driver, $this->connection($driver));
        $frames = [];
        $activePatterns = [];

        $this->spawnPublisher($driver, $channel, ['p1', 'p2'], 0.3);
        $this->armWatchdog($channel);
        $pubSub->psubscribe([$pattern], function (string $ignoredPattern, string $ignoredChannel, string $payload) use (&$frames, &$activePatterns, $pubSub, $pattern, $channel): ?bool {
            $frames[] = func_num_args() . ':' . $ignoredPattern . '|' . $ignoredChannel . '|' . $payload;
            $activePatterns[] = $pubSub->subscribedPatterns();

            return $payload === 'p2' ? false : null;
        });

        $this->assertSame(
            ['3:' . $pattern . '|' . $channel . '|p1', '3:' . $pattern . '|' . $channel . '|p2'],
            $frames
        );
        $this->assertSame([[$pattern], [$pattern]], $activePatterns);
        $this->assertSame([], $pubSub->subscribedPatterns(), '循环结束后会话状态必须清空');
    }

    // R-05：退订确认必须被同一条连接读完，后续命令不能整体错位
    #[DataProvider('driverProvider')]
    public function testCommandsAfterUnsubscribeStayAligned(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('align');
        $key = $channel;
        $pubSub = null;
        $after = [];

        $this->runCoroutine(function () use ($driver, $channel, $key, &$pubSub, &$after): void {
            $connection = $this->connection($driver);
            $pubSub = $this->pubSub($driver, $connection);
            $this->publishLater($this->connection($driver), $channel, ['a1', 'a2'], 0.2);
            $pubSub->subscribe([$channel], static fn(string $c, string $p): ?bool => $p === 'a2' ? false : null);

            // hook 生效后 phpredis 的命令只能在协程内下发，因此同连接复检也留在循环协程里
            $connection->set($key, 'v1');
            $after['exists'] = (int) $connection->exists($key);
            $after['get'] = $this->stringify($connection->get($key));
            $after['miss'] = $this->stringify($connection->get($key . '-missing'));
            $after['ping'] = $this->normalizePing($connection->ping());
        });

        $this->assertSame(1, $after['exists'], 'set 之后的 exists 必须落在这条命令上，不能错位');
        $this->assertSame('v1', $after['get']);
        $this->assertSame('', $after['miss']);
        $this->assertSame('PONG', $after['ping'], 'ping 返回 PONG 而非订阅确认');
        $this->assertFalse($pubSub->isSubscribed());
    }

    // R-06 / R-07：全量退订后同一连接重订阅仍能收消息
    #[DataProvider('driverProvider')]
    public function testResubscribeOnTheSameConnectionDeliversAgain(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('resub');
        $rounds = [];
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$rounds, &$pubSub): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            for ($round = 1; $round <= 2; $round++) {
                $received = [];
                $this->publishLater($this->connection($driver), $channel, ['r' . $round, 'x' . $round], 0.2);
                $pubSub->subscribe([$channel], static function (string $c, string $p) use (&$received): ?bool {
                    $received[] = $p;

                    return str_starts_with($p, 'x') ? false : null;
                });
                $rounds[$round] = $received;
            }
        });

        $this->assertSame(['r1', 'x1'], $rounds[1]);
        $this->assertSame(['r2', 'x2'], $rounds[2]);
        $this->assertFalse($pubSub->isRunning());
        $this->assertSame([], $pubSub->subscribedChannels());
    }

    // R-08：外层 try/catch(\Throwable) 重连式循环，每一轮都能重新收到消息
    #[DataProvider('driverProvider')]
    public function testFailureInOneSessionDoesNotBreakTheNext(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('retry');
        $log = [];
        $pingAfter = null;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$log, &$pubSub, &$pingAfter): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            for ($round = 1; $round <= 2; $round++) {
                $this->publishLater($this->connection($driver), $channel, ['round' . $round, 'finish'], 0.2);
                try {
                    $pubSub->subscribe([$channel], static function (string $c, string $p) use ($round): ?bool {
                        if ($round === 1) {
                            throw new \RuntimeException('round-1 handler failed');
                        }

                        return $p === 'finish' ? false : null;
                    });
                    $log[] = "round{$round}:正常返回";
                } catch (\Throwable $e) {
                    $log[] = "round{$round}:" . $e->getMessage();
                }
            }

            $pingAfter = $this->normalizePing($pubSub->getRedis()->ping());
        });

        $this->assertSame(['round1:round-1 handler failed', 'round2:正常返回'], $log);
        $this->assertTrue($pubSub->stats()['failed'] >= 1, '失败必须计入 stats');
        $this->assertFalse($pubSub->isSubscribed());
        $this->assertSame('PONG', $pingAfter, '失败退出后连接仍可用');
    }

    // R-09：空闲订阅不能被短读超时打断（非协程阻塞循环）
    #[DataProvider('driverProvider')]
    public function testIdleSubscriptionSurvivesShortReadTimeout(string $driver): void
    {
        $this->requireRedis($driver);
        $channel = $this->channelName('idle');
        $connection = $this->connection($driver);

        if ($driver === 'phpredis') {
            $connection->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        } else {
            $previous = (string) ini_get('default_socket_timeout');
            register_shutdown_function(static function () use ($previous): void {
                ini_set('default_socket_timeout', $previous);
            });
            ini_set('default_socket_timeout', '1');
        }

        $pubSub = $this->pubSub($driver, $connection);
        $received = [];
        $startedAt = microtime(true);
        $this->spawnPublisher($driver, $channel, ['after-idle'], 2.5);
        $pubSub->subscribe([$channel], static function (string $c, string $p) use (&$received): bool {
            $received[] = $p;

            return false;
        });

        $this->assertSame(['after-idle'], $received);
        $this->assertGreaterThanOrEqual(2.4, microtime(true) - $startedAt, '订阅必须真的空闲过，而不是立刻超时返回');
    }

    // R-10：phpredis 的 OPT_READ_TIMEOUT 只在循环期间挂起，退出后原样恢复
    public function testPhpRedisReadTimeoutOptionIsRestoredAfterLoop(): void
    {
        $this->requireRedis('phpredis');
        $this->requireCoroutine();
        $channel = $this->channelName('restore');
        $connection = null;
        $optionInsideLoop = null;

        $this->runCoroutine(function () use ($channel, &$connection, &$optionInsideLoop): void {
            $connection = (new PhpRedisConnection())->connect($this->host(), $this->port(), 1.0);
            $connection->setOption(\Redis::OPT_READ_TIMEOUT, 2.5);
            $pubSub = $this->pubSub('phpredis', $connection);
            $this->publishLater($this->connection('phpredis'), $channel, ['one', 'two'], 0.2);
            $pubSub->subscribe([$channel], function (string $c, string $p) use ($connection, &$optionInsideLoop): ?bool {
                $optionInsideLoop = (float) $connection->getOption(\Redis::OPT_READ_TIMEOUT);

                return $p === 'two' ? false : null;
            });
        });

        $this->assertSame(-1.0, $optionInsideLoop, '循环期间读超时必须是无限，否则空闲订阅会被判死');
        $this->assertSame(2.5, (float) $connection->getOption(\Redis::OPT_READ_TIMEOUT), '循环结束后必须恢复业务连接的原值');
    }

    // R-13：订阅态下在同一连接上 publish 必须抛同一领域异常
    #[DataProvider('driverProvider')]
    public function testPublishWhileSubscribedThrowsConflictOnTheSameInstance(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('conflict');
        $captured = null;
        $pingAfter = null;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$captured, &$pubSub, &$pingAfter): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            $this->publishLater($this->connection($driver), $channel, ['c1'], 0.2);
            $pubSub->subscribe([$channel], function (string $c, string $p) use ($pubSub, &$captured): bool {
                try {
                    $pubSub->publish($c, 'from-inside-the-loop');
                    $captured = '未抛异常';
                } catch (\Throwable $e) {
                    $captured = $e::class;
                }

                return false;
            });
            $pingAfter = $this->normalizePing($pubSub->getRedis()->ping());
        });

        $this->assertSame(SubscriptionConflictException::class, $captured);
        $this->assertSame('PONG', $pingAfter);
    }

    // PUB-01 / R-02：跨协程退订只抛异常，绝不触碰被绑定的 socket
    #[DataProvider('driverProvider')]
    public function testUnsubscribeFromAnotherCoroutineIsRejectedWithoutFatal(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('cross');
        $captured = null;
        $pingAfter = null;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$captured, &$pubSub, &$pingAfter): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            $this->publishLater($this->connection($driver), $channel, ['x1'], 0.2);
            $pubSub->subscribe([$channel], function () use (&$captured, $pubSub): bool {
                \Swoole\Coroutine::create(function () use (&$captured, $pubSub): void {
                    try {
                        $pubSub->unsubscribe(['anything']);
                        $captured = '未抛异常';
                    } catch (\Throwable $e) {
                        $captured = $e::class;
                    }
                });
                \Swoole\Coroutine::sleep(0.2);

                return false;
            });
            $pingAfter = $this->normalizePing($pubSub->getRedis()->ping());
        });

        $this->assertSame(SubscriptionConflictException::class, $captured);
        $this->assertFalse($pubSub->isRunning(), '拒绝跨协程退订后循环必须仍能正常结束');
        $this->assertSame('PONG', $pingAfter);
    }

    // PUB-01：stop() 可跨上下文调用，由循环自己退订
    #[DataProvider('driverProvider')]
    public function testStopRequestedFromAnotherCoroutineEndsTheLoop(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('stop');
        $received = [];
        $pingAfter = null;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$received, &$pubSub, &$pingAfter): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            $publisher = $this->connection($driver);
            $pubSubForStop = $pubSub;
            \Swoole\Coroutine::create(static function () use ($publisher, $channel, $pubSubForStop): void {
                \Swoole\Coroutine::sleep(0.2);
                $publisher->publish($channel, 'one');
                \Swoole\Coroutine::sleep(0.2);
                $pubSubForStop->stop();
                // stop 只登记请求，下一条消息负责唤醒循环
                $publisher->publish($channel, 'ignored-after-stop');
            });
            $pubSub->subscribe([$channel], static function (string $c, string $p) use (&$received): void {
                $received[] = $p;
            });
            $pingAfter = $this->normalizePing($pubSub->getRedis()->ping());
        });

        $this->assertSame(['one'], $received, 'stop 之后到达的消息不再派发');
        $this->assertFalse($pubSub->isRunning());
        $this->assertSame('PONG', $pingAfter);
    }

    // R-15：协程派发受 max_concurrency 约束，且每条消息都被处理
    #[DataProvider('driverProvider')]
    public function testCoroutineDispatchIsBoundedByMaxConcurrency(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('bounded');
        $seen = [];
        $peak = 0;
        $inFlight = 0;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$seen, &$peak, &$inFlight, &$pubSub): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver), [
                'dispatch' => AbstractPubSub::DISPATCH_COROUTINE,
                'max_concurrency' => 2,
            ]);
            $publisher = $this->connection($driver);
            $watcher = $pubSub;
            \Swoole\Coroutine::create(function () use ($publisher, $channel, $watcher): void {
                \Swoole\Coroutine::sleep(0.2);
                for ($i = 1; $i <= 6; $i++) {
                    $publisher->publish($channel, 'b' . $i);
                    \Swoole\Coroutine::sleep(0.05);
                }

                // 等 6 条业务消息全部处理完，再补一条唤醒消息让循环结束
                for ($spin = 0; $spin < 200 && $watcher->stats()['completed'] < 6; $spin++) {
                    \Swoole\Coroutine::sleep(0.05);
                }

                $publisher->publish($channel, 'drain');
            });
            $pubSub->subscribe([$channel], function (string $c, string $p) use (&$seen, &$peak, &$inFlight, $pubSub): ?bool {
                if ($p === 'drain') {
                    $pubSub->stop();

                    return null;
                }

                $seen[] = $p;
                $inFlight++;
                $peak = max($peak, $inFlight);
                \Swoole\Coroutine::sleep(0.15);
                $inFlight--;

                return null;
            });
        });

        $this->assertCount(6, $seen);
        $this->assertSame(['b1', 'b2', 'b3', 'b4', 'b5', 'b6'], $seen);
        $this->assertLessThanOrEqual(2, $peak, '并发派发不得超过 max_concurrency');
    }

    // R-01 / PUB-10：配置 on_error 时失败可观测且循环继续投递
    #[DataProvider('driverProvider')]
    public function testOnErrorHookMakesFailuresObservableAndKeepsDelivering(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('onerror');
        $errors = [];
        $seen = [];
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$seen, &$pubSub, &$errors): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver), [
                'on_error' => static function (\Throwable $e, array $context) use (&$errors): void {
                    $errors[] = $e::class . '@' . $context['payload'];
                },
            ]);
            $this->publishLater($this->connection($driver), $channel, ['bad', 'good', 'end'], 0.2);
            $pubSub->subscribe([$channel], static function (string $c, string $p) use (&$seen): ?bool {
                $seen[] = $p;
                if ($p === 'bad') {
                    throw new \RuntimeException('handler failed');
                }

                return $p === 'end' ? false : null;
            });
        });

        $this->assertSame(['bad', 'good', 'end'], $seen);
        $this->assertSame(['RuntimeException@bad'], $errors);
        $stats = $pubSub->stats();
        $this->assertSame(3, $stats['received']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(2, $stats['completed']);
    }

    // R-01 / PUB-10：没有 on_error 时，失败必须冒泡给订阅方而不是静默丢消息
    #[DataProvider('driverProvider')]
    public function testCallbackFailureIsRethrownToTheSubscriber(string $driver): void
    {
        $this->requireRedis($driver);
        $this->requireCoroutine();
        $channel = $this->channelName('bubble');
        $captured = null;
        $pingAfter = null;
        $pubSub = null;

        $this->runCoroutine(function () use ($driver, $channel, &$captured, &$pubSub, &$pingAfter): void {
            $pubSub = $this->pubSub($driver, $this->connection($driver));
            $this->publishLater($this->connection($driver), $channel, ['boom', 'never'], 0.2);
            try {
                $pubSub->subscribe([$channel], static function (string $c, string $p): void {
                    if ($p === 'boom') {
                        throw new \LogicException('handler failed');
                    }
                });
            } catch (\Throwable $e) {
                $captured = $e::class . ': ' . $e->getMessage();
            }
            $pingAfter = $this->normalizePing($pubSub->getRedis()->ping());
        });

        $this->assertSame(LogicException::class . ': handler failed', $captured);
        $stats = $pubSub->stats();
        $this->assertSame(1, $stats['failed']);
        $this->assertFalse($pubSub->isRunning());
        $this->assertFalse($pubSub->isSubscribed());
        $this->assertSame('PONG', $pingAfter);
    }

    // 协程内没开 TCP hook 时必须拒绝订阅，否则会静默死锁（发布协程永远得不到调度）
    public function testSubscribeInsideCoroutineWithoutTcpHooksIsRejected(): void
    {
        $this->requireRedis('predis');
        $this->requireCoroutine();
        $previous = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_TCP);
        $captured = null;

        try {
            \Swoole\Coroutine\run(function () use (&$captured): void {
                $pubSub = $this->pubSubWithoutServer('predis');
                try {
                    $pubSub->subscribe([$this->channelName('nohook')], static fn(): bool => false);
                    $captured = '未抛异常（可能已经死锁）';
                } catch (\Throwable $e) {
                    $captured = $e::class;
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine($previous);
        }

        $this->assertSame(InvalidSubscriptionException::class, $captured);
    }

    /** @return list<array{0: string}> */
    public static function driverProvider(): array
    {
        return [['phpredis'], ['predis']];
    }

    private function requireCoroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('ext-swoole is required for the coroutine cases');
        }
    }

    /**
     * 在独立协程事件循环里执行，模拟 swoolefy 运行时的协程上下文。
     *
     * Redis 连接必须在闭包内创建：phpredis/predis 只有「在 hook 生效后建立」的 socket 才会被协程化，
     * 在协程外建连再进协程订阅，阻塞读会占死整个事件循环。
     *
     * hook 只在协程用例期间生效并即时还原：phpredis 在 hook 开启后禁止在协程外下发命令，
     * 而非协程用例（R-01）正是要验证「普通 CLI + 阻塞循环」这条路径。
     */
    private function runCoroutine(callable $task): void
    {
        $this->requireCoroutine();
        $this->armWatchdog('coroutine 用例');
        $previous = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        try {
            \Swoole\Coroutine\run($task);
        } finally {
            \Swoole\Runtime::enableCoroutine($previous);
        }
    }

    /**
     * 用另一个连接延迟发布，避免与订阅连接互相占用。
     *
     * @param list<string> $messages
     */
    private function publishLater(RedisConnection $connection, string $channel, array $messages, float $delay = 0.2): void
    {
        \Swoole\Coroutine::create(static function () use ($connection, $channel, $messages, $delay): void {
            \Swoole\Coroutine::sleep($delay);
            foreach ($messages as $message) {
                $connection->publish($channel, $message);
                \Swoole\Coroutine::sleep(0.05);
            }
        });
    }

    private function stringify(mixed $value): string
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : '';
        }

        return (string) ($value ?? '');
    }

    private function normalizePing(mixed $ping): string
    {
        $value = $this->stringify($ping);

        return $value === 'true' || $value === '1' ? 'PONG' : $value;
    }

    private function requireDriver(string $driver): void
    {
        if ($driver === 'phpredis' && !extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for the phpredis driver');
        }

        if ($driver === 'predis' && !class_exists(PredisConsumer::class)) {
            self::markTestSkipped('predis/predis is required for the predis driver');
        }
    }

    private function requireRedis(string $driver): void
    {
        $this->requireDriver($driver);

        $socket = @stream_socket_client($this->endpoint(), $errno, $error, 1.0);
        if ($socket === false) {
            self::markTestSkipped(sprintf('redis at %s is unreachable: %s', $this->endpoint(), $error));
        }

        fclose($socket);
    }

    private function host(): string
    {
        return (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    }

    private function port(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    private function endpoint(): string
    {
        return sprintf('tcp://%s:%d', $this->host(), $this->port());
    }

    private function channelName(string $prefix): string
    {
        return 'phpunit:' . $prefix . ':' . bin2hex(random_bytes(4));
    }

    private function connection(string $driver): RedisConnection
    {
        if ($driver === 'phpredis') {
            return (new PhpRedisConnection())->connect($this->host(), $this->port(), 1.0);
        }

        return new PredisConnection([
            'scheme' => 'tcp',
            'host' => $this->host(),
            'port' => $this->port(),
            'timeout' => 1.0,
        ]);
    }

    private function pubSub(string $driver, RedisConnection $connection, array $options = []): AbstractPubSub
    {
        return $driver === 'phpredis' ? new RedisPubSub($connection, $options) : new PredisPubSub($connection, $options);
    }

    private function pubSubWithoutServer(string $driver = 'predis', array $options = []): AbstractPubSub
    {
        return $this->pubSub($driver, $this->connection($driver), $options);
    }
    /**
     * fork 出一个子进程延迟发布，制造「非协程阻塞循环 + 外部消息」的真实场景。
     *
     * 不用 proc_open：本进程一旦跑过 Swoole 事件循环，Swoole 的 socket 延迟关闭会
     * 破坏后续管道描述符（实测 `Unable to copy file descriptor N (for pipe) ... Bad file
     * descriptor`），子进程起不来就只能看着订阅循环挂死。
     *
     * @param list<string> $messages
     */
    private function spawnPublisher(string $driver, string $channel, array $messages, float $delay): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('发布端用例需要 pcntl 与 posix 扩展');
        }

        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() >= 0) {
            $this->fail('spawnPublisher() 只能在非协程上下文调用，协程内 fork 会继承事件循环状态');
        }

        // 忽略 SIGCHLD：子进程用 SIGKILL 结束自己以避免继承的 PHPUnit 状态在退出时被回放，
        // 随之而来的是无人回收的僵尸进程
        pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('无法启动发布进程');
        }

        if ($pid === 0) {
            try {
                \Swoole\Runtime::enableCoroutine(0);
                usleep((int) round($delay * 1000000));
                $connection = $this->connection($driver);
                foreach ($messages as $message) {
                    $connection->publish($channel, $message);
                    usleep(60000);
                }
            } catch (\Throwable) {
                // 子进程失败只会让父进程收不到消息，看门狗负责把它变成可见超时
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        $this->publisherPids[] = $pid;
    }

    /**
     * 给阻塞订阅循环装一个 SIGALRM 看门狗：发布端一旦失能，用例必须以超时失败收场，
     * 而不是让整个套件挂在 blocking read 上。
     */
    private function armWatchdog(string $case): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($case): void {
            fwrite(STDERR, sprintf("\n[watchdog] 订阅循环超过 %d 秒未结束：%s\n", self::LOOP_WATCHDOG, $case));
            exit(1);
        });
        pcntl_alarm(self::LOOP_WATCHDOG);
    }
}
