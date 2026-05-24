<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Resource;

interface ResourceDetectorInterface
{
    public function getResource(): ResourceInfo;
}
