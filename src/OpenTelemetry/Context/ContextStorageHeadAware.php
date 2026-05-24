<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Context;

/**
 * @internal
 */
interface ContextStorageHeadAware
{
    public function head(): ?ContextStorageHead;
}
