<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Service\Model;

use Swoolefy\Library\Nacos\Model\BaseModel;

class HealthChecker extends BaseModel
{
    protected string $type = '';

    public function getType(): string
    {
        return $this->type;
    }
}
