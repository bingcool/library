<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Resource\Detectors;

use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * @deprecated Use Process detector instead.
 */
final class ProcessRuntime implements ResourceDetectorInterface
{
    #[\Override]
    public function getResource(): ResourceInfo
    {
        return ResourceInfo::emptyResource();
    }
}
