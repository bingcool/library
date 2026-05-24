<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Http\Psr\Message;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;

interface MessageFactoryInterface extends RequestFactoryInterface, ServerRequestFactoryInterface, ResponseFactoryInterface
{
}
