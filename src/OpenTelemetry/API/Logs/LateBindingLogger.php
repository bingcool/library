<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Logs;

use Closure;
use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;

class LateBindingLogger implements LoggerInterface
{
    private ?LoggerInterface $logger = null;

    /** @param Closure(): LoggerInterface $factory */
    public function __construct(
        private readonly Closure $factory,
    ) {
    }

    #[\Override]
    public function emit(LogRecord $logRecord): void
    {
        ($this->logger ??= ($this->factory)())->emit($logRecord);
    }

    #[\Override]
    public function isEnabled(?ContextInterface $context = null, ?int $severityNumber = null, ?string $eventName = null): bool
    {
        return true;
    }
}
