<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Configuration\Noop;

use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigProperties;

class NoopConfigProperties implements ConfigProperties
{
    #[\Override]
    public function get(string $id): mixed
    {
        return null;
    }
}
