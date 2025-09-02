<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Resource\Detectors;

use Common\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfoFactory;

final class Composite implements ResourceDetectorInterface
{
    /**
     * @param iterable<ResourceDetectorInterface> $resourceDetectors
     */
    public function __construct(private readonly iterable $resourceDetectors)
    {
    }

    #[\Override]
    public function getResource(): ResourceInfo
    {
        $resource = ResourceInfoFactory::mandatoryResource();
        foreach ($this->resourceDetectors as $resourceDetector) {
            $resource = $resource->merge($resourceDetector->getResource());
        }

        return $resource;
    }
}
