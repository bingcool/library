<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Traits;

use Swoolefy\Library\Nacos\Exception\NacosApiException;
use Swoolefy\Library\Nacos\Http\HttpResponse;

trait TReturnJson
{
    public function __construct(HttpResponse $response)
    {
        parent::__construct($response);
        $jsonData = $response->json(true);
        if (\is_array($jsonData)) {
            foreach ($jsonData as $k => $v) {
                $this->$k = $v;
            }
        } else {
            throw new NacosApiException('Data does not exists');
        }
    }
}
