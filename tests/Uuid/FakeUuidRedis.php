<?php

namespace Swoolefy\Library\Tests\Uuid;

use Swoolefy\Library\Redis\RedisConnection;

final class FakeUuidRedis extends RedisConnection
{
    public int $evalCalls = 0;

    /**
     * @var array<int, mixed>
     */
    public array $evalQueue = [];

    public function eval(...$args)
    {
        $this->evalCalls++;
        if ($this->evalQueue === []) {
            return [];
        }

        return array_shift($this->evalQueue);
    }
}
