<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Contrib\Otlp;

use Common\Library\OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Common\Library\OpenTelemetry\API\Signals;
use Common\Library\OpenTelemetry\SDK\Common\Configuration\Configuration;
use Common\Library\OpenTelemetry\SDK\Common\Configuration\Defaults;
use Common\Library\OpenTelemetry\SDK\Common\Configuration\Variables;
use Common\Library\OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Common\Export\TransportInterface;
use Common\Library\OpenTelemetry\SDK\Registry;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporterFactory implements SpanExporterFactoryInterface
{
    use LogsMessagesTrait;

    private const DEFAULT_COMPRESSION = 'none';

    public function __construct(private readonly ?TransportFactoryInterface $transportFactory = null)
    {
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function create(): SpanExporterInterface
    {
        $transport = $this->buildTransport();

        return new SpanExporter($transport);
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedClass
     */
    private function buildTransport(): TransportInterface
    {
        $protocol = $this->getProtocol();
        $contentType = Protocols::contentType($protocol);
        $endpoint = $this->getEndpoint($protocol);
        $headers = OtlpUtil::getHeaders(Signals::TRACE);
        $compression = $this->getCompression();
        $timeout = $this->getTimeout();

        $factoryClass = Registry::transportFactory($protocol);
        $factory = $this->transportFactory ?: new $factoryClass();

        return $factory->create($endpoint, $contentType, $headers, $compression, $timeout);
    }

    private function getProtocol(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL) ?
            Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL) :
            Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL);
    }

    private function getEndpoint(string $protocol): string
    {
        if (Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT)) {
            return Configuration::getString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT);
        }
        $endpoint = Configuration::has(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            ? Configuration::getString(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            : Defaults::OTEL_EXPORTER_OTLP_ENDPOINT;
        if ($protocol === Protocols::GRPC) {
            return $endpoint . OtlpUtil::method(Signals::TRACE);
        }

        return HttpEndpointResolver::create()->resolveToString($endpoint, Signals::TRACE);
    }

    private function getCompression(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION) ?
            Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION) :
            Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_COMPRESSION, self::DEFAULT_COMPRESSION);
    }

    private function getTimeout(): float
    {
        $value = Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT) ?
            Configuration::getInt(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT) :
            Configuration::getInt(Variables::OTEL_EXPORTER_OTLP_TIMEOUT);

        return $value/1000;
    }
}
