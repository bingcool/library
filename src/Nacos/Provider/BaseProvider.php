<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider;

use Common\Library\Nacos\Client;

abstract class BaseProvider
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
