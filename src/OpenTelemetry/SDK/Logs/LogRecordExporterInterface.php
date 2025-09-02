<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;

interface LogRecordExporterInterface
{
    /**
     * @param iterable<ReadableLogRecord> $batch
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface;
    public function forceFlush(?CancellationInterface $cancellation = null): bool;
    public function shutdown(?CancellationInterface $cancellation = null): bool;
}
