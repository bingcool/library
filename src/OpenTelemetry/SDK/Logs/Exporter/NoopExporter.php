<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs\Exporter;

use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\CompletedFuture;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;
use Common\Library\OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

class NoopExporter implements LogRecordExporterInterface
{
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
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
}
