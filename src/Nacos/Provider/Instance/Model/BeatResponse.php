<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Instance\Model;

use Swoolefy\Library\Nacos\Provider\Model\BaseResponse;
use Swoolefy\Library\Nacos\Provider\Traits\TReturnJson;

class BeatResponse extends BaseResponse
{
    use TReturnJson;

    protected int $clientBeatInterval = 0;

    protected int $code = 0;

    protected bool $lightBeatEnabled = false;

    public function getClientBeatInterval(): int
    {
        return $this->clientBeatInterval;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getLightBeatEnabled(): bool
    {
        return $this->lightBeatEnabled;
    }
}
