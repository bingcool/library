<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\Behavior;

use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\CompletedFuture;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanDataInterface;

trait SpanExporterTrait
{
    private bool $running = true;

    /** @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.7.0/specification/trace/sdk.md#shutdown-2 */
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->running = false;

        return true;
    }

    /** @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.7.0/specification/trace/sdk.md#forceflush-2 */
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    /**
     * @param iterable<SpanDataInterface> $batch
     * @return FutureInterface<bool>
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if (!$this->running) {
            return new CompletedFuture(false);
        }

        return new CompletedFuture($this->doExport($batch)); /** @phpstan-ignore-line */
    }

    /**
     * @param iterable<SpanDataInterface> $spans Batch of spans to export
     */
    abstract protected function doExport(iterable $spans): bool; /** @phpstan-ignore-line */
}
