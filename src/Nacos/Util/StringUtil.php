<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Util;

class StringUtil
{
    private function __construct()
    {
    }

    public static function convertBoolToString(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
