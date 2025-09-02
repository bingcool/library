<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Metrics;

/**
 * @internal
 */
final class RegisteredInstrument
{
    public function __construct(
        public bool $dormant,
        public readonly object $configKeepAlive,
    ) {
    }
}
