<?php

declare(strict_types=1);

\Common\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Common\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_BAGGAGE,
    \Common\Library\OpenTelemetry\API\Baggage\Propagation\BaggagePropagator::getInstance()
);
\Common\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Common\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_TRACECONTEXT,
    \Common\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()
);
\Common\Library\OpenTelemetry\SDK\Registry::registerTextMapPropagator(
    \Common\Library\OpenTelemetry\SDK\Common\Configuration\KnownValues::VALUE_NONE,
    \Common\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator::getInstance()
);
