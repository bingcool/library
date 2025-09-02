<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;

/**
 * The exemplar spec is not yet stable, and can change at any time.
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/sdk.md#exemplar
 */
final class AllExemplarFilter implements ExemplarFilterInterface
{
    #[\Override]
    public function accepts($value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): bool
    {
        return true;
    }
}
