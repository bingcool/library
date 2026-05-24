<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Resolver;

interface ResolverInterface
{
    public function retrieveValue(string $variableName): mixed;

    public function hasVariable(string $variableName): bool;
}
