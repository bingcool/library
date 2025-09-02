<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;

/**
 * @todo implement (optional) isEnabled: https://github.com/open-telemetry/opentelemetry-specification/blob/v1.45.0/specification/logs/sdk.md#enabled-1
 */
interface LogRecordProcessorInterface
{
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void;
    public function shutdown(?CancellationInterface $cancellation = null): bool;
    public function forceFlush(?CancellationInterface $cancellation = null): bool;
}
