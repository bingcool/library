<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Config\Model;

use Common\Library\Nacos\Provider\Model\BaseResponse;
use Common\Library\Nacos\Provider\Traits\TReturnJson;

class HistoryResponse extends BaseResponse
{
    use THistoryItem;
    use TReturnJson;
}
