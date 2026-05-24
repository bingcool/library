<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs\Processor;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\ReadWriteLogRecord;

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
