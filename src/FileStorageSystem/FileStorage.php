<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem;

/**
 * 可选静态入口（测试 / 脚本）；生产推荐 DI 注入 FileStorageManager。
 */
final class FileStorage
{
    private static ?FileStorageManager $manager = null;

    /**
     * 注册全局 Manager，供静态 disk() 委托使用。
     *
     * @param FileStorageManager $manager 已配置好的磁盘管理器
     */
    public static function setManager(FileStorageManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * 清空静态 Manager 引用（单测 teardown 用）。
     */
    public static function clearManager(): void
    {
        self::$manager = null;
    }

    /**
     * 获取已注册的 Manager；未 setManager 时抛异常，避免静默误用。
     *
     * @return FileStorageManager
     */
    public static function manager(): FileStorageManager
    {
        if (self::$manager === null) {
            throw new \RuntimeException('FileStorage manager is not set; call FileStorage::setManager() first');
        }

        return self::$manager;
    }

    /**
     * 按名称解析 FileDisk，语义同 FileStorageManager::disk()。
     *
     * @param string|null $name 配置中的 provider 名；null 为 default_provider
     * @return FileDisk
     */
    public static function disk(?string $name = null): FileDisk
    {
        return self::manager()->disk($name);
    }
}
