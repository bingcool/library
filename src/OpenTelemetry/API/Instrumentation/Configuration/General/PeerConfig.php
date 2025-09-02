<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\Configuration\General;

use Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation\GeneralInstrumentationConfiguration;

class PeerConfig implements GeneralInstrumentationConfiguration
{
    public function __construct(public readonly array $config)
    {
    }
}
