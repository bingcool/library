<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\MetricRegistry;

use Closure;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Instrument;

/**
 * @internal
 */
interface MetricWriterInterface
{
    public function record(Instrument $instrument, $value, iterable $attributes = [], $context = null): void;

    public function registerCallback(Closure $callback, Instrument $instrument, Instrument ...$instruments): int;

    public function unregisterCallback(int $callbackId): void;
    public function enabled(Instrument $instrument): bool;
}
