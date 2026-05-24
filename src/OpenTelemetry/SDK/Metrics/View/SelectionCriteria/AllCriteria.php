<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteria;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Instrument;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;

final class AllCriteria implements SelectionCriteriaInterface
{
    /**
     * @param iterable<SelectionCriteriaInterface> $criteria
     */
    public function __construct(private readonly iterable $criteria)
    {
    }

    #[\Override]
    public function accepts(Instrument $instrument, InstrumentationScopeInterface $instrumentationScope): bool
    {
        foreach ($this->criteria as $criterion) {
            if (!$criterion->accepts($instrument, $instrumentationScope)) {
                return false;
            }
        }

        return true;
    }
}
