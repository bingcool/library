<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\Instrumentation;

use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

interface InstrumentationScopeInterface
{
    public function getName(): string;

    public function getVersion(): ?string;

    public function getSchemaUrl(): ?string;

    public function getAttributes(): AttributesInterface;
}
