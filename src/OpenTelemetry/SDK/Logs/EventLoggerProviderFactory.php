<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\SDK\Sdk;

/**
 * @deprecated
 */
class EventLoggerProviderFactory
{
    public function create(LoggerProviderInterface $loggerProvider): EventLoggerProviderInterface
    {
        if (Sdk::isDisabled()) {
            return NoopEventLoggerProvider::getInstance();
        }

        return new EventLoggerProvider($loggerProvider);
    }
}
