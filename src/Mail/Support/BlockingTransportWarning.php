<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * mail() 和 sendmail 子进程等待会阻塞当前 Worker 的所有协程。
 * 每个进程每种传输只告警一次，避免每封信刷日志。
 */
final class BlockingTransportWarning
{
    /** @var array<string, true> */
    private static array $warned = [];

    /**
     * 仅在协程上下文（getCid() >= 0）告警；CLI 或传统 FPM 下不输出噪声。
     * 用静态表按传输名去重，保证每个进程每种传输最多一条。
     */
    public static function warnIfCoroutine(string $transport): void
    {
        if (isset(self::$warned[$transport])) {
            return;
        }
        if (!class_exists(\Swoole\Coroutine::class) || \Swoole\Coroutine::getCid() < 0) {
            return;
        }

        self::$warned[$transport] = true;
        error_log(sprintf(
            '[swoolefy/mail] transport [%s] blocks the current coroutine worker; prefer smtp or move it to a task process',
            $transport
        ));
    }
}
