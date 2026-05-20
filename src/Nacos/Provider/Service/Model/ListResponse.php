<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Service\Model;

use Common\Library\Nacos\Provider\Model\BaseResponse;
use Common\Library\Nacos\Provider\Traits\TReturnJson;

class ListResponse extends BaseResponse
{
    use TReturnJson;

    protected int $count = 0;

    /**
     * @var string[]
     */
    protected array $doms = [];

    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @return string[]
     */
    public function getDoms(): array
    {
        return $this->doms;
    }
}
