<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;

interface LogRecordExporterInterface
{
    /**
     * @param iterable<ReadableLogRecord> $batch
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface;
    public function forceFlush(?CancellationInterface $cancellation = null): bool;
    public function shutdown(?CancellationInterface $cancellation = null): bool;
}
