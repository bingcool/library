<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Model;

abstract class BaseRequest
{
    /**
     * @return mixed
     */
    abstract public function getRequestBody();
}
