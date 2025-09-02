<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration;

interface ConfigProviderInterface
{
    public function getInstrumentationConfig(): ConfigProperties;
}
