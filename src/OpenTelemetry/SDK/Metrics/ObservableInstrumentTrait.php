<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use ArrayAccess;
use function assert;
use Common\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObserverInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\MetricRegistry\MetricWriterInterface;

/**
 * @internal
 */
trait ObservableInstrumentTrait
{
    public function __construct(
        private readonly MetricWriterInterface $writer,
        private readonly Instrument $instrument,
        private readonly ReferenceCounterInterface $referenceCounter,
        private readonly ArrayAccess $destructors,
    ) {
        assert($this instanceof InstrumentHandle);

        $this->referenceCounter->acquire();
    }

    public function __destruct()
    {
        $this->referenceCounter->release();
    }

    public function getHandle(): Instrument
    {
        return $this->instrument;
    }

    /**
     * @param callable(ObserverInterface): void $callback
     */
    public function observe(callable $callback): ObservableCallbackInterface
    {
        return AsynchronousInstruments::observe(
            $this->writer,
            $this->destructors,
            $callback,
            [$this->instrument],
            $this->referenceCounter,
        );
    }

    public function isEnabled(): bool
    {
        return $this->writer->enabled($this->instrument);
    }
}
