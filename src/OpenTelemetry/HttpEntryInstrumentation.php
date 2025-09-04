<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry;

use Common\Library\CurlProxy\OpentelemetryMiddleware;
use Common\Library\OpenTelemetry\API\Common\Time\Clock;
use Common\Library\OpenTelemetry\Contrib\Otlp\SpanExporter;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SemConv\ResourceAttributes;
use Common\Library\Exception\OpenTelemetryException;
use Common\Library\OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use Common\Library\OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use Common\Library\OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use Common\Library\OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use Common\Library\OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use Common\Library\OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use Common\Library\OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

class HttpEntryInstrumentation
{

    public static $globalTracerProvider;

    const OTEL_SAMPLER_TYPE_ALWAYS_ON = 'always_on';

    const OTEL_SAMPLER_TYPE_ALWAYS_OFF = 'always_off';

    const OTEL_SAMPLER_TYPE_PARENTBASED_ALWAYS_ON = 'parentbased_always_on';

    const OTEL_SAMPLER_TYPE_TRACE_ID_RATIO = 'trace_id_ratio';

    const OTEL_TRACE_PROVIER = '__tracer_provier';

    const OTEL_TRACE_ROOT_FLAG = OpentelemetryMiddleware::OPENTELEMETRY_TRACE_ROOT_FLAG;

    public static function register(bool $rootSpanFlag = false)
    {
        $OTEL_PHP_AUTOLOAD_ENABLED = env('OTEL_PHP_AUTOLOAD_ENABLED', false);
        if (!$OTEL_PHP_AUTOLOAD_ENABLED) {
            return;
        }

        if (SwooleContext::has(self::OTEL_TRACE_PROVIER)) {
            return SwooleContext::get(self::OTEL_TRACE_PROVIER);
        }

        $OTEL_MAX_QUEUE_SIZE = intval(env('OTEL_MAX_QUEUE_SIZE', 512));
        $OTEL_SCHEDULE_DELAY = intval(env('OTEL_SCHEDULE_DELAY_MILLISECONDS', 1));
        $OTEL_EXPORT_TIMEOUT = intval(env('OTEL_EXPORT_TIMEOUT', 30));

        $resource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => env('OTEL_RESOURCE_SERVICE_NAME',"default-server"),
            ResourceAttributes::HOST_NAME => gethostname(),
            ResourceAttributes::TELEMETRY_SDK_LANGUAGE => 'php',
            ResourceAttributes::TELEMETRY_SDK_NAME => 'opentelemetry-php',
        ]));
        
        $endpoint = env('OTEL_EXPORTER_OTLP_ENDPOINT');
        if (empty($endpoint)) {
            throw new OpenTelemetryException("env `OTEL_EXPORTER_OTLP_ENDPOINT` is empty!!! ");
        }

        $headers = [];
        $authenticationToken = env('OTEL_EXPORTER_OTLP_AUTHENTICATION_TOKEN', '');
        if (!empty($authenticationToken)) {
            $headers['Authentication'] = $authenticationToken;
        }

        $transport = (new OtlpHttpTransportFactory())->create($endpoint . '/v1/traces', 'application/json', $headers);
        $exporter  = new SpanExporter($transport);
        $processor = new BatchSpanProcessor(
            $exporter,
            Clock::getDefault(),
            $OTEL_MAX_QUEUE_SIZE,
            $OTEL_SCHEDULE_DELAY,
            $OTEL_EXPORT_TIMEOUT * 1000
        );

        $OTEL_SAMPLER_TYPE = env('OTEL_SAMPLER_TYPE', self::OTEL_SAMPLER_TYPE_ALWAYS_ON);
        switch ($OTEL_SAMPLER_TYPE) {
            case self::OTEL_SAMPLER_TYPE_PARENTBASED_ALWAYS_ON:
                $rootSampler = new AlwaysOnSampler();
                $sampler = new ParentBased($rootSampler);
                break;
            case self::OTEL_SAMPLER_TYPE_TRACE_ID_RATIO:
                $sampler = new TraceIdRatioBasedSampler(floatval(env('OTEL_SAMPLER_TRACE_ID_RATIO', 0.5)));
                break;
            case self::OTEL_SAMPLER_TYPE_ALWAYS_OFF:
                $sampler = new AlwaysOffSampler();
                break;
            case self::OTEL_SAMPLER_TYPE_ALWAYS_ON:
            default:
                $sampler = new AlwaysOnSampler();
                break;
        }

        $provider = (new TracerProviderBuilder())
            ->setResource($resource)
            ->addSpanProcessor($processor)
            ->setSampler($sampler)
            ->build();

        SwooleContext::set(self::OTEL_TRACE_PROVIER, $provider);

        if ($rootSpanFlag) {
            SwooleContext::set(self::OTEL_TRACE_ROOT_FLAG, 1);
        } else {
            SwooleContext::set(self::OTEL_TRACE_ROOT_FLAG, 0);
        }

        return $provider;
    }
}