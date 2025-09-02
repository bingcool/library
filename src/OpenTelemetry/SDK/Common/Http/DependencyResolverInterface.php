<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\Http;

use Common\Library\OpenTelemetry\SDK\Common\Http\HttpPlug\Client\ResolverInterface as HttpPlugClientResolverInterface;
use Common\Library\OpenTelemetry\SDK\Common\Http\Psr\Client\ResolverInterface as PsrClientResolverInterface;
use Common\Library\OpenTelemetry\SDK\Common\Http\Psr\Message\FactoryResolverInterface;

interface DependencyResolverInterface extends FactoryResolverInterface, PsrClientResolverInterface, HttpPlugClientResolverInterface
{
}
