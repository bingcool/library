<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Http;

final class RequestMethod
{
    public const GET = 'GET';

    public const POST = 'POST';

    public const PUT = 'PUT';

    public const DELETE = 'DELETE';

    public const PATCH = 'PATCH';

    private function __construct()
    {
    }
}
