<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration\Noop;

use Common\Library\OpenTelemetry\API\Configuration\ConfigProperties;
use Common\Library\OpenTelemetry\API\Configuration\ConfigProviderInterface;

class NoopConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getInstrumentationConfig(): ConfigProperties
    {
        return new NoopConfigProperties();
    }
}
