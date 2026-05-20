<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Model;

use Common\Library\Nacos\Http\HttpResponse;

abstract class BaseResponse implements \JsonSerializable
{
    private HttpResponse $response;

    public function __construct(HttpResponse $response)
    {
        $this->response = $response;
    }

    public function getResponse(): HttpResponse
    {
        return $this->response;
    }

    /**
     * {@inheritDoc}
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $result = [];
        foreach ($this as $k => $v) {
            if ('response' === $k) {
                continue;
            }
            $result[$k] = $v;
        }

        return $result;
    }
}
