<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandler;

use Common\Library\OpenTelemetry\SDK\Metrics\ReferenceCounterInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

final class ImmediateStalenessHandlerFactory implements StalenessHandlerFactoryInterface
{
    #[\Override]
    public function create(): ReferenceCounterInterface&StalenessHandlerInterface
    {
        return new ImmediateStalenessHandler();
    }
}
