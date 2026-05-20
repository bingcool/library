<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Auth\Model;

use Common\Library\Nacos\Provider\Model\BaseResponse;
use Common\Library\Nacos\Provider\Traits\TReturnJson;

class LoginResponse extends BaseResponse
{
    use TReturnJson;

    protected string $data = '';

    protected string $username = '';

    protected string $accessToken = '';

    protected int $tokenTtl = 0;

    protected bool $globalAdmin = false;

    public function getData(): string
    {
        return $this->data;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getTokenTtl(): int
    {
        return $this->tokenTtl;
    }

    public function getGlobalAdmin(): bool
    {
        return $this->globalAdmin;
    }
}
