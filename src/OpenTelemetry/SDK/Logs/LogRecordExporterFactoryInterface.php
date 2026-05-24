<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

interface LogRecordExporterFactoryInterface
{
    public function create(): LogRecordExporterInterface;
}
