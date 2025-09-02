<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\Http\HttpPlug\Client;

use Http\Client\HttpAsyncClient;

interface ResolverInterface
{
    public function resolveHttpPlugAsyncClient(): HttpAsyncClient;
}
