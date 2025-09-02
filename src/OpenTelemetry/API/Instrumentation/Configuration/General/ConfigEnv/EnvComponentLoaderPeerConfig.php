<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Instrumentation\Configuration\General\ConfigEnv;

use Common\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use Common\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use Common\Library\OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use Common\Library\OpenTelemetry\API\Configuration\Context;
use Common\Library\OpenTelemetry\API\Instrumentation\AutoInstrumentation\GeneralInstrumentationConfiguration;
use Common\Library\OpenTelemetry\API\Instrumentation\Configuration\General\PeerConfig;

/**
 * @implements EnvComponentLoader<GeneralInstrumentationConfiguration>
 */
final class EnvComponentLoaderPeerConfig implements EnvComponentLoader
{
    #[\Override]
    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): GeneralInstrumentationConfiguration
    {
        return new PeerConfig([]);
    }

    #[\Override]
    public function name(): string
    {
        return PeerConfig::class;
    }
}
