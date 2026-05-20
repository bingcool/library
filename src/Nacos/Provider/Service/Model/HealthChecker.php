<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Service\Model;

use Common\Library\Nacos\Model\BaseModel;

class HealthChecker extends BaseModel
{
    protected string $type = '';

    public function getType(): string
    {
        return $this->type;
    }
}
