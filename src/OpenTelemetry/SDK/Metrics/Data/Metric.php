<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

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
