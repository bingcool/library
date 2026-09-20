<?php

namespace Swoolefy\Library\Tests\Redis;

use Swoolefy\Library\Redis\Redis;

final class RedisRetryStub extends Redis
{
    public int $reconnects = 0;

    public function __construct(object $native)
    {
        $this->redis = $native;
        $this->setRetryOptions(['delay' => 0]);
    }

    protected function buildRedis()
    {
        // keep injected fake client
    }

    protected function reConnect()
    {
        $this->reconnects++;
        if ($this->password && method_exists($this->redis, 'auth')) {
            $this->redis->auth($this->password);
        }
        $this->restoreSelectedDatabase(function (int $database) {
            $this->redis->select($database);
        });
    }
}
