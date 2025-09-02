<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Resource\Detectors;

use Common\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

final class Constant implements ResourceDetectorInterface
{
    public function __construct(private readonly ResourceInfo $resourceInfo)
    {
    }

    #[\Override]
    public function getResource(): ResourceInfo
    {
        return $this->resourceInfo;
    }
}
