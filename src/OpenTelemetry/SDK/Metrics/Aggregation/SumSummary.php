<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Aggregation;

final class SumSummary
{
    public function __construct(public float|int $value)
    {
    }
}
