<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute;

class LogRecordAttributeValidator implements AttributeValidatorInterface
{
    #[\Override]
    public function validate($value): bool
    {
        return true;
    }

    #[\Override]
    public function getInvalidMessage(): string
    {
        //not required as this validator always returns true
        return 'unused';
    }
}
