<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Logs;

use InvalidArgumentException;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Configuration;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Variables;
use Swoolefy\Library\OpenTelemetry\SDK\Logs\Exporter\NoopExporter;
use Swoolefy\Library\OpenTelemetry\SDK\Registry;

class ExporterFactory
{
    public function create(): LogRecordExporterInterface
    {
        $exporters = Configuration::getList(Variables::OTEL_LOGS_EXPORTER);
        if (1 !== count($exporters)) {
            throw new InvalidArgumentException(sprintf('Configuration %s requires exactly 1 exporter', Variables::OTEL_LOGS_EXPORTER));
        }
        $exporter = $exporters[0];
        if ($exporter === 'none') {
            return new NoopExporter();
        }
        $factory = Registry::logRecordExporterFactory($exporter);

        return $factory->create();
    }
}
