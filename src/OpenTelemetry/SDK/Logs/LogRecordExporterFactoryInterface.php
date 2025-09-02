<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Logs;

interface LogRecordExporterFactoryInterface
{
    public function create(): LogRecordExporterInterface;
}
