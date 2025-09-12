<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry;

use Common\Library\CurlProxy\OpentelemetryMiddleware;
use Common\Library\OpenTelemetry\API\Common\Time\Clock;
use Common\Library\OpenTelemetry\API\Globals;
use Common\Library\OpenTelemetry\API\Instrumentation\Configurator;
use Common\Library\OpenTelemetry\Context\Context as OpenTelemetryContext;
use Common\Library\OpenTelemetry\Context\ContextStorage;
use Common\Library\OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use Common\Library\OpenTelemetry\Contrib\Otlp\SpanExporter;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\Attributes;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Common\Library\OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use Common\Library\OpenTelemetry\SDK\Trace\TracerProvider;
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

        $OTEL_MAX_QUEUE_SIZE = intval(env('OTEL_MAX_QUEUE_SIZE', 32));
        $OTEL_SCHEDULE_DELAY = intval(env('OTEL_SCHEDULE_DELAY_MILLISECONDS', 500));
        $OTEL_EXPORT_TIMEOUT = intval(env('OTEL_EXPORT_TIMEOUT', 30));
        $OTEL_SAMPLER_TYPE = env('OTEL_SAMPLER_TYPE', self::OTEL_SAMPLER_TYPE_ALWAYS_ON);

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

        if (env('OTEL_SAMPLER_BATCH_SPAN_ENABLED', false)) {
            $spanProcessor = new BatchSpanProcessor(
                $exporter,
                Clock::getDefault(),
                $OTEL_MAX_QUEUE_SIZE,
                $OTEL_SCHEDULE_DELAY,
                $OTEL_EXPORT_TIMEOUT * 1000
            );

        } else {
            $spanProcessor = new SimpleSpanProcessor($exporter);
        }

        switch ($OTEL_SAMPLER_TYPE) {
            case self::OTEL_SAMPLER_TYPE_PARENTBASED_ALWAYS_ON:
                $rootSampler = new AlwaysOnSampler();
                $sampler = new ParentBased($rootSampler);
                break;
            case self::OTEL_SAMPLER_TYPE_TRACE_ID_RATIO:
                $rateio = floatval(env('OTEL_SAMPLER_TRACE_ID_RATIO', 0.5));
                $ratioSampler = new TraceIdRatioBasedSampler($rateio);
                $sampler = new ParentBased($ratioSampler);
                break;
            case self::OTEL_SAMPLER_TYPE_ALWAYS_OFF:
                $sampler = new AlwaysOffSampler();
                break;
            case self::OTEL_SAMPLER_TYPE_ALWAYS_ON:
            default:
                $sampler = new AlwaysOnSampler();
                break;
        }

        $tracerProvider = (new TracerProviderBuilder())
            ->setResource($resource)
            ->addSpanProcessor($spanProcessor)
            ->setSampler($sampler)
            ->build();

        // Use Swoole context storage
        $contextStorage = new SwooleContextStorage(new ContextStorage());
        OpenTelemetryContext::setStorage($contextStorage);

        // Register the tracer provider
        Globals::registerInitializer(fn(Configurator $configurator) => $configurator->withTracerProvider($tracerProvider));
        // 2s flush data
        goTick(2 * 1000, function () use($tracerProvider) {
            $tracerProvider->forceFlush();
        });
    }
}