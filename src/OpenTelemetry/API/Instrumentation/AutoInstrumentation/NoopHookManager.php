<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Closure;

final class NoopHookManager implements HookManagerInterface
{
    #[\Override]
    public function hook(?string $class, string $function, ?Closure $preHook = null, ?Closure $postHook = null): void
    {
        // no-op
    }
}
