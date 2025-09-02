<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Data;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

final class Metric
{
    public function __construct(
        public readonly InstrumentationScopeInterface $instrumentationScope,
        public readonly ResourceInfo $resource,
        public readonly string $name,
        public readonly ?string $unit,
        public readonly ?string $description,
        public readonly DataInterface $data,
    ) {
    }
}
