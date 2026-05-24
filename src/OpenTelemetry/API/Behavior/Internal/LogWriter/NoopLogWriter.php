<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\API\Behavior\Internal\LogWriter;

class NoopLogWriter implements LogWriterInterface
{
    #[\Override]
    public function write($level, string $message, array $context): void
    {
        //do nothing
    }
}
