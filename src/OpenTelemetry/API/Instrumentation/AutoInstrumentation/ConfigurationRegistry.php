<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation;

use Common\Library\OpenTelemetry\API\Configuration\ConfigProperties;

final class ConfigurationRegistry implements ConfigProperties
{
    private array $configurations = [];

    public function add(InstrumentationConfiguration $configuration): self
    {
        $this->configurations[$configuration::class] = $configuration;

        return $this;
    }

    /**
     * @template C of InstrumentationConfiguration
     * @psalm-suppress MoreSpecificImplementedParamType
     * @param class-string<C> $id
     * @return C|null
     */
    #[\Override]
    public function get(string $id): ?InstrumentationConfiguration
    {
        return $this->configurations[$id] ?? null;
    }
}
