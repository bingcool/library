<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Metrics\Noop;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableCounterInterface;

/**
 * @internal
 */
final class NoopObservableCounter implements ObservableCounterInterface
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
