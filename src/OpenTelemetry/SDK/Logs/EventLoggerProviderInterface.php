<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\API\Logs as API;

/**
 * @phan-suppress PhanDeprecatedInterface
 */
interface EventLoggerProviderInterface extends API\EventLoggerProviderInterface
{
    public function forceFlush(): bool;
}
