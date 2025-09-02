<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\SDK\Sdk;

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
