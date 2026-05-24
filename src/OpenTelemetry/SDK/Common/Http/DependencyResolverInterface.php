<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Http;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Http\HttpPlug\Client\ResolverInterface as HttpPlugClientResolverInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Http\Psr\Client\ResolverInterface as PsrClientResolverInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Http\Psr\Message\FactoryResolverInterface;

interface DependencyResolverInterface extends FactoryResolverInterface, PsrClientResolverInterface, HttpPlugClientResolverInterface
{
}
