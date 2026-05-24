<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics;

interface StalenessHandlerFactoryInterface
{
    public function create(): StalenessHandlerInterface&ReferenceCounterInterface;
}
