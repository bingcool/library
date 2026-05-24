<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Metrics\Noop;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;

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
