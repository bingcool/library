<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs\Processor;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use Common\Library\OpenTelemetry\SDK\Logs\ReadWriteLogRecord;

class NoopLogRecordProcessor implements LogRecordProcessorInterface
{
    public static function getInstance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /**
     * @codeCoverageIgnore
     */
    #[\Override]
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
