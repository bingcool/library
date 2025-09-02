<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Resource\Detectors;

use Common\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * @deprecated Use Host detector instead.
 */
final class OperatingSystem implements ResourceDetectorInterface
{
    #[\Override]
    public function getResource(): ResourceInfo
    {
        return ResourceInfo::emptyResource();
    }
}
