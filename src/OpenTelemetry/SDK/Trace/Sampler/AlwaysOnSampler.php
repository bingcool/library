<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\Sampler;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SamplerInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SamplingResult;
use Common\Library\OpenTelemetry\SDK\Trace\Span;

/**
 * This implementation of the SamplerInterface always records.
 * Example:
 * ```
 * use Common\Library\OpenTelemetry\SDK\Trace\AlwaysOnSampler;
 * $sampler = new AlwaysOnSampler();
 * ```
 */
class AlwaysOnSampler implements SamplerInterface
{
    /**
     * Returns true because we always want to sample.
     * {@inheritdoc}
     */
    #[\Override]
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $parentSpan = Span::fromContext($parentContext);
        $parentSpanContext = $parentSpan->getContext();
        $traceState = $parentSpanContext->getTraceState();

        return new SamplingResult(
            SamplingResult::RECORD_AND_SAMPLE,
            [],
            $traceState
        );
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'AlwaysOnSampler';
    }
}
