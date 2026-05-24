<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\Sampler;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SamplerInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SamplingResult;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\Span;

/**
 * This implementation of the SamplerInterface always skips record.
 * Example:
 * ```
 * use Swoolefy\Library\OpenTelemetry\SDK\Trace\AlwaysOffSampler;
 * $sampler = new AlwaysOffSampler();
 * ```
 */
class AlwaysOffSampler implements SamplerInterface
{
    /**
     * Returns false because we never want to sample.
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
            SamplingResult::DROP,
            [],
            $traceState
        );
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'AlwaysOffSampler';
    }
}
