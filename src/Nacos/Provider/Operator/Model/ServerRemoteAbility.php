<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Operator\Model;

use Common\Library\Nacos\Model\BaseModel;

class ServerRemoteAbility extends BaseModel
{
    protected bool $supportRemoteConnection = false;

    public function getSupportRemoteConnection(): bool
    {
        return $this->supportRemoteConnection;
    }
}
