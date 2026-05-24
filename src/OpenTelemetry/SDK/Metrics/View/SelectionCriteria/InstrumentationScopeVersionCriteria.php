<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteria;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Instrument;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;

final class InstrumentationScopeVersionCriteria implements SelectionCriteriaInterface
{
    public function __construct(private readonly ?string $version)
    {
    }

    #[\Override]
    public function accepts(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): bool
    {
        return $this->version === $instrumentationScope->getVersion();
    }
}
