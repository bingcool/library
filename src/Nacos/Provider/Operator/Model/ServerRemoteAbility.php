<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Operator\Model;

use Swoolefy\Library\Nacos\Model\BaseModel;

class ServerRemoteAbility extends BaseModel
{
    protected bool $supportRemoteConnection = false;

    public function getSupportRemoteConnection(): bool
    {
        return $this->supportRemoteConnection;
    }
}
