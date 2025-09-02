<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Metrics\Noop;

use Common\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;

/**
 * @internal
 */
final class NoopObservableCallback implements ObservableCallbackInterface
{
    #[\Override]
    public function detach(): void
    {
        // no-op
    }
}
