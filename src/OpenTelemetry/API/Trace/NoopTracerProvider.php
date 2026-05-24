<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Trace;

class NoopTracerProvider implements TracerProviderInterface
{
    #[\Override]
    public function getTracer(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): TracerInterface {
        return NoopTracer::getInstance();
    }
}
