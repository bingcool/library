<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteria;

use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Instrument;
use Common\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;

final class InstrumentationScopeNameCriteria implements SelectionCriteriaInterface
{
    public function __construct(private readonly string $name)
    {
    }

    #[\Override]
    public function accepts(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): bool
    {
        return $this->name === $instrumentationScope->getName();
    }
}
