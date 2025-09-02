<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Resource\Detectors;

use function class_exists;
use Composer\InstalledVersions;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Common\Library\OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Detect service name and version of root package. Not included in `all` detectors.
 */
final class Composer implements ResourceDetectorInterface
{
    #[\Override]
    public function getResource(): ResourceInfo
    {
        if (!class_exists(InstalledVersions::class)) {
            return ResourceInfoFactory::emptyResource();
        }

        $attributes = [
            ResourceAttributes::SERVICE_NAME => InstalledVersions::getRootPackage()['name'],
            ResourceAttributes::SERVICE_VERSION => InstalledVersions::getRootPackage()['pretty_version'],
        ];

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }
}
