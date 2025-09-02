<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Context;

/**
 * @internal
 */
interface ContextStorageHeadAware
{
    public function head(): ?ContextStorageHead;
}
