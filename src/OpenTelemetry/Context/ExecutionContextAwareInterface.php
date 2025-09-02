<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Context;

interface ExecutionContextAwareInterface
{
    public function fork(int|string $id): void;

    public function switch(int|string $id): void;

    public function destroy(int|string $id): void;
}
