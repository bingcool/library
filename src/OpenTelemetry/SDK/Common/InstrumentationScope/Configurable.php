<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\InstrumentationScope;

interface Configurable
{
    public function updateConfigurator(Configurator $configurator): void;
}
