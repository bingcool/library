<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Trace\Behavior\SpanExporterDecoratorTrait;

abstract class AbstractDecorator
{
    use SpanExporterDecoratorTrait;
}
