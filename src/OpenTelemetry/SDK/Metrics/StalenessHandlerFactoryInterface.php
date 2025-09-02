<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

interface StalenessHandlerFactoryInterface
{
    public function create(): StalenessHandlerInterface&ReferenceCounterInterface;
}
