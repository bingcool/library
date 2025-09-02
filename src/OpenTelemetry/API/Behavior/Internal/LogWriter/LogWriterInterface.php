<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Behavior\Internal\LogWriter;

interface LogWriterInterface
{
    public function write($level, string $message, array $context): void;
}
