<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\API\Logs as API;
use Swoolefy\Library\OpenTelemetry\SDK\Common\InstrumentationScope\Configurable;

interface LoggerProviderInterface extends API\LoggerProviderInterface, Configurable
{
    public function shutdown(): bool;
    public function forceFlush(): bool;
}
