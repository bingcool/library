<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration\ConfigEnv;

use Common\Library\OpenTelemetry\API\Configuration\Context;

interface EnvComponentLoaderRegistry
{
    /**
     * @template T
     * @param class-string<T> $type
     * @return T
     */
    public function load(string $type, string $name, EnvResolver $env, Context $context): mixed;
}
