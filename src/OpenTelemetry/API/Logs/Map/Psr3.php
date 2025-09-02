<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Logs\Map;

use Common\Library\OpenTelemetry\API\Logs\Severity;

class Psr3
{
    /**
     * Maps PSR-3 severity level (string) to the appropriate opentelemetry severity
     *
     * @deprecated Use Severity::fromPsr3
     */
    public static function severityNumber(string $level): Severity
    {
        return Severity::fromPsr3($level);
    }
}
