<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute;

interface AttributesFactoryInterface
{
    public function builder(iterable $attributes = [], ?AttributeValidatorInterface $attributeValidator = null): AttributesBuilderInterface;
}
