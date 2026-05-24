<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Config\Model;

use Swoolefy\Library\Nacos\Provider\Model\BaseResponse;
use Swoolefy\Library\Nacos\Provider\Traits\TReturnJson;

class HistoryResponse extends BaseResponse
{
    use THistoryItem;
    use TReturnJson;
}
