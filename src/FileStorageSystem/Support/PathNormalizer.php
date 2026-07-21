<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

use Swoolefy\Library\FileStorageSystem\Exception\InvalidObjectPathException;

/**
 * 对象 path 统一规范化（所有 FileDisk path 入口必须经过）。
 */
final class PathNormalizer
{
    /**
     * 将逻辑对象路径规范为相对 key：防 `..` 逃逸、拒绝绝对路径/URL、拒绝 NUL。
     *
     * @param string $path 调用方传入的对象路径
     * @return string 规范化后的相对路径（不以 / 开头）
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidObjectPathException('Empty or invalid object path');
        }
        // 禁止 Windows 盘符路径与 scheme URL，避免把本地/远程绝对地址当作对象 key
        if (preg_match('#^[a-zA-Z]:/#', $path) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            throw new InvalidObjectPathException("Absolute or URL path not allowed: {$path}");
        }

        $path = ltrim($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new InvalidObjectPathException('Path escapes root');
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidObjectPathException('Path resolves to empty');
        }

        return implode('/', $segments);
    }
}
