<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Trace;

use Common\Library\OpenTelemetry\Context\Context;
use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\Context\ContextStorageInterface;

final class NoopSpanBuilder implements SpanBuilderInterface
{
    private ContextInterface|false|null $parentContext = null;

    public function __construct(private readonly ContextStorageInterface $contextStorage)
    {
    }

    #[\Override]
    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        $this->parentContext = $context;

        return $this;
    }

    #[\Override]
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    #[\Override]
    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        return $this;
    }

    #[\Override]
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        return $this;
    }

    #[\Override]
    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    #[\Override]
    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    #[\Override]
    public function startSpan(): SpanInterface
    {
        $parentContext = Context::resolve($this->parentContext, $this->contextStorage);
        $span = Span::fromContext($parentContext);
        if ($span->isRecording()) {
            $span = Span::wrap($span->getContext());
        }

        return $span;
    }
}
