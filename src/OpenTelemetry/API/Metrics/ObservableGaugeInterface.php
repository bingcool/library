<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Metrics;

interface ObservableGaugeInterface extends AsynchronousInstrument
{
    /**
     * @param callable(ObserverInterface): void $callback function responsible for
     *        reporting the measurements
     * @return ObservableCallbackInterface token to detach callback
     */
    public function observe(callable $callback): ObservableCallbackInterface;
}
