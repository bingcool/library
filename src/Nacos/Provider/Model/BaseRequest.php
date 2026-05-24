<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Model;

abstract class BaseRequest
{
    /**
     * @return mixed
     */
    abstract public function getRequestBody();
}
