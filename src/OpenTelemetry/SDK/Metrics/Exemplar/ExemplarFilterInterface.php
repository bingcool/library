<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics\Exemplar;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * The exemplar spec is not yet stable, and can change at any time.
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/sdk.md#exemplar
 */
interface ExemplarFilterInterface
{
    public function accepts(float|int $value, AttributesInterface $attributes, ContextInterface $context, int $timestamp): bool;
}
