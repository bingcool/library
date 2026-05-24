<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace;

use Swoolefy\Library\OpenTelemetry\API\Trace as API; /** @phan-suppress-current-line PhanUnreferencedUseNormal */
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Resource\ResourceInfo;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanProcessor\MultiSpanProcessor;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;

/**
 * Stores shared state/config between all {@see API\TracerInterface} created via the same {@see API\TracerProviderInterface}.
 */
final class TracerSharedState
{
    private readonly SpanProcessorInterface $spanProcessor;

    private ?bool $shutdownResult = null;

    public function __construct(
        private readonly IdGeneratorInterface $idGenerator,
        private readonly ResourceInfo $resource,
        private readonly SpanLimits $spanLimits,
        private readonly SamplerInterface $sampler,
        array $spanProcessors,
    ) {
        $this->spanProcessor = match (count($spanProcessors)) {
            0 => NoopSpanProcessor::getInstance(),
            1 => $spanProcessors[0],
            default => new MultiSpanProcessor(...$spanProcessors),
        };
    }

    public function hasShutdown(): bool
    {
        return null !== $this->shutdownResult;
    }

    public function getIdGenerator(): IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    public function getSpanLimits(): SpanLimits
    {
        return $this->spanLimits;
    }

    public function getSampler(): SamplerInterface
    {
        return $this->sampler;
    }

    public function getSpanProcessor(): SpanProcessorInterface
    {
        return $this->spanProcessor;
    }

    /**
     * Returns `false` is the provider is already shutdown, otherwise `true`.
     */
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->shutdownResult ?? ($this->shutdownResult = $this->spanProcessor->shutdown($cancellation));
    }
}
