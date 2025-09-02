<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Logs;

use Common\Library\OpenTelemetry\Context\ContextInterface;

/**
 * @deprecated
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/logs/event-api.md#events-api-interface
 */
interface EventLoggerInterface
{
    public function emit(
        string $name,
        mixed $body = null,
        ?int $timestamp = null,
        ?ContextInterface $context = null,
        ?Severity $severityNumber = null,
        iterable $attributes = [],
    ): void;
}
