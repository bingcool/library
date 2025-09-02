<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

use Closure;

interface StalenessHandlerInterface
{
    public function onStale(Closure $callback): void;
}
