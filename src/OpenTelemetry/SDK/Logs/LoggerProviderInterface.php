<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\API\Logs as API;
use Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurable;

interface LoggerProviderInterface extends API\LoggerProviderInterface, Configurable
{
    public function shutdown(): bool;
    public function forceFlush(): bool;
}
