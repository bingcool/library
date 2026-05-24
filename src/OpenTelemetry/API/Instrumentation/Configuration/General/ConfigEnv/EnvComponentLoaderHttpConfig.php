<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Instrumentation\Configuration\General\ConfigEnv;

use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use Swoolefy\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use Swoolefy\Library\OpenTelemetry\API\Configuration\Context;
use Swoolefy\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation\GeneralInstrumentationConfiguration;
use Swoolefy\Library\OpenTelemetry\API\Instrumentation\Configuration\General\HttpConfig;

/**
 * @implements EnvComponentLoader<GeneralInstrumentationConfiguration>
 */
final class EnvComponentLoaderHttpConfig implements EnvComponentLoader
{
    #[\Override]
    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): GeneralInstrumentationConfiguration
    {
        return new HttpConfig([
            'client' => [
                'request_captured_headers' => $env->list('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS') ?? [],
                'response_captured_headers' => $env->list('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS') ?? [],
            ],
            'server' => [
                'request_captured_headers' => $env->list('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS') ?? [],
                'response_captured_headers' => $env->list('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS') ?? [],
            ],
        ]);
    }

    #[\Override]
    public function name(): string
    {
        return HttpConfig::class;
    }
}
