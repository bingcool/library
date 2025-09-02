<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Trace;

use Common\Library\OpenTelemetry\Context\Context;
use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\Context\ContextKeyInterface;

class LocalRootSpan
{
    /**
     * Retrieve the local root span. This is the root-most active span which has
     * a remote or invalid parent.
     * If there is no active local root span, then an invalid span is returned.
     */
    public static function current(): SpanInterface
    {
        return self::fromContext(Context::getCurrent());
    }

    /**
     * Retrieve the local root span from a Context.
     */
    public static function fromContext(ContextInterface $context): SpanInterface
    {
        return $context->get(self::key()) ?? Span::getInvalid();
    }

    /**
     * @internal
     */
    public static function store(ContextInterface $context, SpanInterface $span): ContextInterface
    {
        return $context->with(self::key(), $span);
    }

    /**
     * @internal
     * @return ContextKeyInterface<SpanInterface>
     */
    public static function key(): ContextKeyInterface
    {
        static $key;

        return $key ??= Context::createKey(self::class);
    }

    /**
     * @internal
     */
    public static function isLocalRoot(ContextInterface $parentContext): bool
    {
        $spanContext = Span::fromContext($parentContext)->getContext();

        return !$spanContext->isValid() || $spanContext->isRemote();
    }
}
