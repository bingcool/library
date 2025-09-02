<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Common\Library\OpenTelemetry\API\Configuration\ConfigProperties;

interface Instrumentation
{
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void;
}
