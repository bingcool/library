<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration\Noop;

use Common\Library\OpenTelemetry\API\Configuration\ConfigProperties;

class NoopConfigProperties implements ConfigProperties
{
    #[\Override]
    public function get(string $id): mixed
    {
        return null;
    }
}
