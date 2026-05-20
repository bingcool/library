<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Model;

use Common\Library\Nacos\Provider\Traits\TInitProperties;

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
