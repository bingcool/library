<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Metrics\Stream;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Metrics\Data\Exemplar;

/**
 * @internal
 *
 * @template T
 */
final class Metric
{
    /**
     * @param array<AttributesInterface> $attributes
     * @param array<T> $summaries
     * @param array<Exemplar> $exemplars
     */
    public function __construct(
        public array $attributes,
        public array $summaries,
        public int $timestamp,
        public array $exemplars = [],
    ) {
    }
}
