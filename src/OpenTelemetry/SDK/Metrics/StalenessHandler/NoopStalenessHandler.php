<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandler;

use Closure;
use Common\Library\OpenTelemetry\SDK\Metrics\ReferenceCounterInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

/**
 * @internal
 */
final class NoopStalenessHandler implements StalenessHandlerInterface, ReferenceCounterInterface
{
    #[\Override]
    public function acquire(bool $persistent = false): void
    {
        // no-op
    }

    #[\Override]
    public function release(): void
    {
        // no-op
    }

    #[\Override]
    public function onStale(Closure $callback): void
    {
        // no-op
    }
}
