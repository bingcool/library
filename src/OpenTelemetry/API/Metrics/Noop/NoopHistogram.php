<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Metrics\Noop;

use Common\Library\OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal
 */
final class NoopHistogram implements HistogramInterface
{
    #[\Override]
    public function record($amount, iterable $attributes = [], $context = null): void
    {
        // no-op
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return false;
    }
}
