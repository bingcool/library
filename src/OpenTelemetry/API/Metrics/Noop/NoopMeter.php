<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Metrics\Noop;

use Common\Library\OpenTelemetry\API\Metrics\AsynchronousInstrument;
use Common\Library\OpenTelemetry\API\Metrics\CounterInterface;
use Common\Library\OpenTelemetry\API\Metrics\GaugeInterface;
use Common\Library\OpenTelemetry\API\Metrics\HistogramInterface;
use Common\Library\OpenTelemetry\API\Metrics\MeterInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObservableCounterInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use Common\Library\OpenTelemetry\API\Metrics\UpDownCounterInterface;

final class NoopMeter implements MeterInterface
{
    #[\Override]
    public function batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments): ObservableCallbackInterface
    {
        return new NoopObservableCallback();
    }

    #[\Override]
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        return new NoopCounter();
    }

    #[\Override]
    public function createObservableCounter(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): ObservableCounterInterface
    {
        return new NoopObservableCounter();
    }

    #[\Override]
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
    {
        return new NoopHistogram();
    }

    #[\Override]
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
    {
        return new NoopGauge();
    }

    #[\Override]
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): ObservableGaugeInterface
    {
        return new NoopObservableGauge();
    }

    #[\Override]
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounterInterface
    {
        return new NoopUpDownCounter();
    }

    #[\Override]
    public function createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): ObservableUpDownCounterInterface
    {
        return new NoopObservableUpDownCounter();
    }
}
