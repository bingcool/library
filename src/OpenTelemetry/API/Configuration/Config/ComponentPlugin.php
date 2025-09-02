<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\API\Configuration\Config;

use function class_alias;
use Common\Library\OpenTelemetry\API\Configuration\Context;

/**
 * A component plugin that can be used to create a component.
 *
 * @template T
 */
interface ComponentPlugin
{
    /**
     * Creates the component that is provided by this plugin.
     *
     * @param Context $context context used for creation
     * @return T created component
     */
    public function create(Context $context): mixed;
}

/** @phpstan-ignore-next-line @phan-suppress-next-line PhanUndeclaredClassReference */
class_alias(ComponentPlugin::class, \OpenTelemetry\Config\SDK\Configuration\ComponentPlugin::class);
