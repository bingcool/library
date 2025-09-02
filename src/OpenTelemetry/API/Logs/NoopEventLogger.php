<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Logs;

use Common\Library\OpenTelemetry\Context\ContextInterface;

/**
 * @phan-suppress PhanDeprecatedInterface
 */
class NoopEventLogger implements EventLoggerInterface
{
    public static function instance(): self
    {
        static $instance;
        $instance ??= new self();

        return $instance;
    }

    #[\Override]
    public function emit(string $name, mixed $body = null, ?int $timestamp = null, ?ContextInterface $context = null, Severity|int|null $severityNumber = null, iterable $attributes = []): void
    {
    }
}
