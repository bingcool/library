<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\API\Logs as API;

/**
 * @phan-suppress PhanDeprecatedInterface
 */
interface EventLoggerProviderInterface extends API\EventLoggerProviderInterface
{
    public function forceFlush(): bool;
}
