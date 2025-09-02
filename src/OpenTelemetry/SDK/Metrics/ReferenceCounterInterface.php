<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

/**
 * @internal
 */
interface ReferenceCounterInterface
{
    public function acquire(bool $persistent = false): void;

    public function release(): void;
}
