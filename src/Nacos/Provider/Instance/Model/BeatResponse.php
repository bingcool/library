<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Instance\Model;

use Common\Library\Nacos\Provider\Model\BaseResponse;
use Common\Library\Nacos\Provider\Traits\TReturnJson;

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
