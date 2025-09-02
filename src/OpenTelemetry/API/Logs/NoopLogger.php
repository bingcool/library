<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Logs;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Psr\Log\LoggerTrait;

class NoopLogger implements LoggerInterface
{
    use LoggerTrait;

    public static function getInstance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /**
     * @codeCoverageIgnore
     */
    #[\Override]
    public function emit(LogRecord $logRecord): void
    {
    }

    /**
     * @codeCoverageIgnore
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
    }

    #[\Override]
    public function isEnabled(?ContextInterface $context = null, ?int $severityNumber = null, ?string $eventName = null): bool
    {
        return false;
    }
}
