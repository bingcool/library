<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\Behavior;

use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanConverterInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanDataInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanExporter\NullSpanConverter;

trait UsesSpanConverterTrait
{
    private ?SpanConverterInterface $converter = null;

    protected function setSpanConverter(SpanConverterInterface $converter): void
    {
        $this->converter = $converter;
    }

    public function getSpanConverter(): SpanConverterInterface
    {
        if (null === $this->converter) {
            $this->converter = new NullSpanConverter();
        }

        return $this->converter;
    }

    /**
     * @return array
     * @psalm-suppress PossiblyNullReference
     */
    protected function convertSpan(SpanDataInterface $span): array
    {
        return $this->getSpanConverter()->convert([$span]);
    }
}
