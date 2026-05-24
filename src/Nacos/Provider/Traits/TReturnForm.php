<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Traits;

use Swoolefy\Library\Nacos\Http\HttpResponse;

trait TReturnForm
{
    public function __construct(HttpResponse $response)
    {
        parent::__construct($response);
        parse_str($response->body(), $result);
        foreach ($result as $k => $v) {
            $this->$k = $v;
        }
    }
}
