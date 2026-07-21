<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Support;

/**
 * 将 options 中的 visibility 字符串映射为公有/私有判断（与 ACL 选项对齐）。
 */
final class VisibilityConverter
{
    /**
     * 是否为 public 可见性（大小写不敏感）。
     *
     * @param string|null $visibility 如 public / private
     */
    public static function isPublic(?string $visibility): bool
    {
        return strtolower((string) $visibility) === 'public';
    }

    /**
     * 是否为非 public（含 null、private 等）。
     *
     * @param string|null $visibility 如 public / private
     */
    public static function isPrivate(?string $visibility): bool
    {
        return !self::isPublic($visibility);
    }
}
