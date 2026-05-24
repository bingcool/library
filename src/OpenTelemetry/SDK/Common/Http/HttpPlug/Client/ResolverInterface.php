<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Http\HttpPlug\Client;

use Http\Client\HttpAsyncClient;

interface ResolverInterface
{
    public function resolveHttpPlugAsyncClient(): HttpAsyncClient;
}
