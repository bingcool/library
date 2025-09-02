<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Metrics\Noop;

use Common\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use Common\Library\OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;

/**
 * @internal
 */
final class NoopObservableUpDownCounter implements ObservableUpDownCounterInterface
{
    #[\Override]
    public function observe(callable $callback, bool $weaken = false): ObservableCallbackInterface
    {
        return new NoopObservableCallback();
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return false;
    }
}
