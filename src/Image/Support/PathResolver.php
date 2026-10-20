<?php

declare(strict_types=1);

namespace Swoolefy\Library\Image\Support;

use Swoolefy\Library\Image\Exception\InvalidImageException;

/**
 * 本地图片/字体路径解析与根目录白名单校验。
 *
 * 相对路径统一以 `base_path`（默认 `APP_PATH`，再退化为进程 CWD）为基准，
 * 不再隐式依赖当前工作目录。
 */
final class PathResolver
{
    /**
     * 解析为真实绝对路径，并校验文件存在且可读。
     *
     * @param string $path 绝对路径，或相对 `$basePath` 的路径
     * @param string|null $basePath 相对路径基准；null 时用 `APP_PATH` / CWD
     *
     * @throws InvalidImageException 含 `..`、文件不存在或不可读
     */
    public static function resolve(string $path, ?string $basePath = null): string
    {
        if ($path === '' || preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $path) === 1) {
            throw new InvalidImageException('Image path is not allowed');
        }

        $real = realpath(self::absolute($path, $basePath));
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new InvalidImageException('Image file is not readable');
        }

        return $real;
    }

    /**
     * 校验已解析路径是否位于任一允许根目录内（含根本身）。
     *
     * 空数组表示不额外限制；相对根目录同样以 `$basePath` 为基准。
     *
     * @param list<string> $allowedRoots
     *
     * @throws InvalidImageException 路径不在任何允许根目录内
     */
    public static function assertWithinRoots(string $path, array $allowedRoots, ?string $basePath = null): void
    {
        if ($allowedRoots === []) {
            return;
        }

        $real = realpath($path);
        if ($real === false) {
            throw new InvalidImageException('Image file is not readable');
        }

        foreach ($allowedRoots as $root) {
            if (!is_string($root) || trim($root) === '') {
                continue;
            }

            $rootReal = realpath(self::absolute(trim($root), $basePath));
            if ($rootReal === false || !is_dir($rootReal)) {
                continue;
            }

            $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
            if ($real === $rootReal || str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                return;
            }
        }

        throw new InvalidImageException('Image path is outside allowed roots');
    }

    /** 是否为绝对路径（Unix 或 Windows 盘符） */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /** 拼接绝对路径；相对路径以 `$basePath` / APP_PATH / CWD 为基准 */
    private static function absolute(string $path, ?string $basePath): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        $base = $basePath;
        if ($base === null || trim($base) === '') {
            $base = defined('APP_PATH') ? (string) constant('APP_PATH') : (getcwd() ?: '.');
        }

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
