<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Exception;

use Common\Library\Nacos\Http\HttpResponse;

class NacosApiException extends NacosException
{
    private ?HttpResponse $response = null;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?HttpResponse $response = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?HttpResponse
    {
        return $this->response;
    }
}
