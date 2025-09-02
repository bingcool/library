<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Context;

if (ZendObserverFiber::isEnabled()) {
    ZendObserverFiber::init();
}
