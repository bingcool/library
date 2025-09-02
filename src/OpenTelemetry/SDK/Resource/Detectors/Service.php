<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Resource\Detectors;

use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Common\Configuration\Configuration;
use Common\Library\OpenTelemetry\SDK\Common\Configuration\Variables;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SemConv\ResourceAttributes;
use Ramsey\Uuid\Uuid;

/**
 * @see https://github.com/open-telemetry/semantic-conventions/tree/main/docs/resource#service-experimental
 */
final class Service implements ResourceDetectorInterface
{
    #[\Override]
    public function getResource(): ResourceInfo
    {
        static $serviceInstanceId;
        $serviceInstanceId ??= Uuid::uuid4()->toString();
        $serviceName = Configuration::has(Variables::OTEL_SERVICE_NAME)
            ? Configuration::getString(Variables::OTEL_SERVICE_NAME)
            : null;

        $attributes = [
            ResourceAttributes::SERVICE_INSTANCE_ID => $serviceInstanceId,
        ];
        if ($serviceName !== null) {
            $attributes[ResourceAttributes::SERVICE_NAME] = $serviceName;
        }

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }
}
