<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs\Exporter;

use ArrayObject;
use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\CompletedFuture;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class InMemoryExporter implements LogRecordExporterInterface
{
    public function __construct(private readonly ArrayObject $storage = new ArrayObject())
    {
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($batch as $record) {
            $this->storage->append($record);
        }

        return new CompletedFuture(true);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function getStorage(): ArrayObject
    {
        return $this->storage;
    }
}
