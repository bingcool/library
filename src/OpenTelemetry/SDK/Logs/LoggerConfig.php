<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Config;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\ConfigTrait;

class LoggerConfig implements Config
{
    use ConfigTrait;

    public static function default(): self
    {
        return new self();
    }
}
