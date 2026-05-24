<?php

declare(strict_types=1);

\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_BAGGAGE,
    \Swoolefy\Library\OpenTelemetry\API\Baggage\Propagation\BaggagePropagator::getInstance()
);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_TRACECONTEXT,
    \Swoolefy\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()
);
\Swoolefy\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_NONE,
    \Swoolefy\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator::getInstance()
);
