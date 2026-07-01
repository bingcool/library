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

namespace Swoolefy\Library\Db\Concern;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\Db\Interceptor\TenantLineHandlerInterface;

/**
 * 保存 Model 租户 Scope 使用的 TenantLineHandler，与 SQL 拦截器共享同一处理器实例。
 *
 * 协程环境下 Handler 存入当前协程 Context，避免多协程并发时互相覆盖。
 * Worker 启动阶段（非协程）注册的 Handler 作为 fallback，供未显式 bind 的请求复用。
 */
class TenantScopeContext
{
    private const CONTEXT_HANDLER_KEY = '__tenant_line_handler';

    /**
     * 非协程环境（Worker 启动、CLI 测试）下的默认 Handler。
     */
    private static ?TenantLineHandlerInterface $fallbackHandler = null;

    public static function bindHandler(TenantLineHandlerInterface $handler): void
    {
        if (self::isCoroutineContextAvailable()) {
            SwooleContext::set(self::CONTEXT_HANDLER_KEY, $handler);

            return;
        }

        self::$fallbackHandler = $handler;
    }

    public static function getHandler(): ?TenantLineHandlerInterface
    {
        if (self::isCoroutineContextAvailable() && SwooleContext::has(self::CONTEXT_HANDLER_KEY)) {
            $handler = SwooleContext::get(self::CONTEXT_HANDLER_KEY);
            if ($handler instanceof TenantLineHandlerInterface) {
                return $handler;
            }
        }

        return self::$fallbackHandler;
    }

    public static function clearHandler(): void
    {
        if (self::isCoroutineContextAvailable() && SwooleContext::has(self::CONTEXT_HANDLER_KEY)) {
            SwooleContext::delete(self::CONTEXT_HANDLER_KEY);
        }

        self::$fallbackHandler = null;
    }

    private static function isCoroutineContextAvailable(): bool
    {
        return class_exists(SwooleContext::class)
            && \Swoole\Coroutine::getCid() >= 0;
    }
}
