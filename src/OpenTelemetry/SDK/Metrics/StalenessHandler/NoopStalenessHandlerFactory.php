<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\StalenessHandler;

use Swoolefy\Library\OpenTelemetry\SDK\Metrics\ReferenceCounterInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerFactoryInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\StalenessHandlerInterface;

final class NoopStalenessHandlerFactory implements StalenessHandlerFactoryInterface
{
    #[\Override]
    public function create(): ReferenceCounterInterface&StalenessHandlerInterface
    {
        static $instance;

        return $instance ??= new NoopStalenessHandler();
    }
}
