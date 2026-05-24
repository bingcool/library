<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute;

interface AttributeValidatorInterface
{
    public function validate($value): bool;
    public function getInvalidMessage(): string;
}
