<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\Behavior;

use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanDataInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

trait SpanExporterDecoratorTrait
{
    protected SpanExporterInterface $decorated;

    /**
     * @param iterable<SpanDataInterface> $batch
     * @return FutureInterface<bool>
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $batch = $this->beforeExport($batch);
        $response = $this->decorated->export($batch, $cancellation);
        $response->map(fn (bool $result) => $this->afterExport($batch, $result));

        return $response;
    }

    abstract protected function beforeExport(iterable $spans): iterable;

    abstract protected function afterExport(iterable $spans, bool $exportSuccess): void;

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->decorated->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->decorated->forceFlush($cancellation);
    }

    public function setDecorated(SpanExporterInterface $decorated): void
    {
        $this->decorated = $decorated;
    }
}
