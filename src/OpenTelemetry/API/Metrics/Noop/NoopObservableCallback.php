<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Metrics\Noop;

use Swoolefy\Library\OpenTelemetry\API\Metrics\ObservableCallbackInterface;

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
