<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration;

interface ConfigProperties
{
    public function get(string $id): mixed;
}
