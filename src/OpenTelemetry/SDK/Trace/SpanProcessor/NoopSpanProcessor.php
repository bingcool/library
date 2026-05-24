<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanProcessor;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Trace\SpanProcessorInterface;

class NoopSpanProcessor implements SpanProcessorInterface
{
    private static ?SpanProcessorInterface $instance = null;

    public static function getInstance(): SpanProcessorInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** @inheritDoc */
    #[\Override]
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
    } //@codeCoverageIgnore

    /** @inheritDoc */
    #[\Override]
    public function onEnd(ReadableSpanInterface $span): void
    {
    } //@codeCoverageIgnore

    /** @inheritDoc */
    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    /** @inheritDoc */
    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->forceFlush();
    }
}
