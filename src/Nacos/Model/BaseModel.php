<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Model;

use Swoolefy\Library\Nacos\Provider\Traits\TInitProperties;

abstract class BaseModel implements \JsonSerializable
{
    use TInitProperties;

    /**
     * {@inheritDoc}
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $result = [];
        foreach ($this as $k => $v) {
            $result[$k] = $v;
        }

        return $result;
    }
}
