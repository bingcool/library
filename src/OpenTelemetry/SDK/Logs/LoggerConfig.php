<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Config;
use Swoolefy\Library\OpenTelemetry\SDK\Common\InstrumentationScope\ConfigTrait;

class LoggerConfig implements Config
{
    use ConfigTrait;

    public static function default(): self
    {
        return new self();
    }
}
