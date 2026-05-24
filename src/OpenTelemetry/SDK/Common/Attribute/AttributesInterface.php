<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Common\Attribute;

use Countable;
use Traversable;

/**
 * @psalm-suppress MissingTemplateParam
 */
interface AttributesInterface extends Traversable, Countable
{
    public function has(string $name): bool;

    public function get(string $name);

    public function getDroppedAttributesCount(): int;

    public function toArray(): array;
}
