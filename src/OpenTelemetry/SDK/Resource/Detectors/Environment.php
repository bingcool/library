<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Resource\Detectors;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Configuration;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Variables;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Swoolefy\Library\OpenTelemetry\SemConv\ResourceAttributes;

/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/resource/sdk.md#specifying-resource-information-via-an-environment-variable
 */
final class Environment implements ResourceDetectorInterface
{
    #[\Override]
    public function getResource(): ResourceInfo
    {
        $attributes = Configuration::has(Variables::OTEL_RESOURCE_ATTRIBUTES)
            ? self::decode(Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES, []))
            : [];

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    private static function decode(array $attributes): array
    {
        return array_map('urldecode', $attributes);
    }
}
