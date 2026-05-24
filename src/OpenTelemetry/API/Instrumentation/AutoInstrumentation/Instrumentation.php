<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigProperties;

interface Instrumentation
{
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void;
}
