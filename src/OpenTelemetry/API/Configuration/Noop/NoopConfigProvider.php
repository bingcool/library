<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Configuration\Noop;

use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigProperties;
use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigProviderInterface;

class NoopConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getInstrumentationConfig(): ConfigProperties
    {
        return new NoopConfigProperties();
    }
}
