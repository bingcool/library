<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

interface InstrumentationScopeInterface
{
    public function getName(): string;

    public function getVersion(): ?string;

    public function getSchemaUrl(): ?string;

    public function getAttributes(): AttributesInterface;
}
