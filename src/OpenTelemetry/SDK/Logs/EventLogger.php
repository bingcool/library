<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\API\Common\Time\ClockInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\EventLoggerInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\LoggerInterface;
use Swoolefy\Library\OpenTelemetry\API\Logs\LogRecord;
use Swoolefy\Library\OpenTelemetry\API\Logs\Severity;
use Swoolefy\Library\OpenTelemetry\Context\Context;
use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;

/**
 * @deprecated
 * @phan-suppress PhanDeprecatedInterface
 */
class EventLogger implements EventLoggerInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.32.0/specification/logs/event-sdk.md#emit-event
     */
    #[\Override]
    public function emit(
        string $name,
        mixed $body = null,
        ?int $timestamp = null,
        ?ContextInterface $context = null,
        ?Severity $severityNumber = null,
        iterable $attributes = [],
    ): void {
        $logRecord = new LogRecord();
        $logRecord->setAttribute('event.name', $name);
        $logRecord->setAttributes($attributes);
        $logRecord->setAttribute('event.name', $name);
        $logRecord->setBody($body);
        $logRecord->setTimestamp($timestamp ?? $this->clock->now());
        $logRecord->setContext($context ?? Context::getCurrent());
        $logRecord->setSeverityNumber($severityNumber ?? Severity::INFO);

        $this->logger->emit($logRecord);
    }
}
