<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth;

use Swoolefy\Library\Oauth\Contracts\OauthClientInterface;

/**
 * 可选静态入口（测试脚本 / 非 HTTP 场景）。
 *
 * 生产环境请优先：
 * `Application::getApp()->get('oauth')`（协程单例，由 component 闭包创建）。
 *
 * 进程级 static 长期持有 Manager 有串态风险，仅建议在单测或一次性 CLI 中使用。
 */
final class Oauth
{
    /** @var OauthManager|null 进程内可选挂载点；生产勿依赖 */
    private static ?OauthManager $manager = null;

    /**
     * 注册全局 Manager，供静态 provider() 委托。
     */
    public static function setManager(OauthManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * 清空静态引用（单测 tearDown 必调用，避免用例互相污染）。
     */
    public static function clearManager(): void
    {
        self::$manager = null;
    }

    /**
     * 获取已注册的 Manager。
     *
     * @throws \RuntimeException 未先调用 setManager
     */
    public static function manager(): OauthManager
    {
        if (self::$manager === null) {
            throw new \RuntimeException('Oauth manager is not set; call Oauth::setManager() first');
        }

        return self::$manager;
    }

    /**
     * 语法糖：等价于 manager()->provider($name)。
     *
     * @param string $name provider 配置键（必须显式指定，无默认渠道）
     */
    public static function provider(string $name): OauthClientInterface
    {
        return self::manager()->provider($name);
    }
}
