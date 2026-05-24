<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Context;

if (ZendObserverFiber::isEnabled()) {
    ZendObserverFiber::init();
}
