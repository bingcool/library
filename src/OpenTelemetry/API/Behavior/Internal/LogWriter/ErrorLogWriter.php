<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Behavior\Internal\LogWriter;

class ErrorLogWriter implements LogWriterInterface
{
    #[\Override]
    public function write($level, string $message, array $context): void
    {
        error_log(Formatter::format($level, $message, $context));
    }
}
