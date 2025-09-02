<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Trace;

use Common\Library\OpenTelemetry\Context\Context;
use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\Context\ContextKeys;
use Common\Library\OpenTelemetry\Context\ScopeInterface;

abstract class Span implements SpanInterface
{
    private static ?self $invalidSpan = null;

    /** @inheritDoc */
    #[\Override]
    final public static function fromContext(ContextInterface $context): SpanInterface
    {
        return $context->get(ContextKeys::span()) ?? self::getInvalid();
    }

    /** @inheritDoc */
    #[\Override]
    final public static function getCurrent(): SpanInterface
    {
        return self::fromContext(Context::getCurrent());
    }

    /** @inheritDoc */
    #[\Override]
    final public static function getInvalid(): SpanInterface
    {
        if (null === self::$invalidSpan) {
            self::$invalidSpan = new NonRecordingSpan(SpanContext::getInvalid());
        }

        return self::$invalidSpan;
    }

    /** @inheritDoc */
    #[\Override]
    final public static function wrap(SpanContextInterface $spanContext): SpanInterface
    {
        if (!$spanContext->isValid()) {
            return self::getInvalid();
        }

        return new NonRecordingSpan($spanContext);
    }

    /** @inheritDoc */
    #[\Override]
    final public function activate(): ScopeInterface
    {
        return Context::getCurrent()->withContextValue($this)->activate();
    }

    /** @inheritDoc */
    #[\Override]
    final public function storeInContext(ContextInterface $context): ContextInterface
    {
        if (LocalRootSpan::isLocalRoot($context)) {
            $context = LocalRootSpan::store($context, $this);
        }

        return $context->with(ContextKeys::span(), $this);
    }
}
