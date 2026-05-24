<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Metrics\Noop;

use Swoolefy\Library\OpenTelemetry\API\Metrics\CounterInterface;

/**
 * @internal
 */
final class NoopCounter implements CounterInterface
{
    #[\Override]
    public function add($amount, iterable $attributes = [], $context = null): void
    {
        // no-op
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return false;
    }
}
