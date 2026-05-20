<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Traits;

use Common\Library\Nacos\Exception\NacosApiException;
use Common\Library\Nacos\Http\HttpResponse;

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
