<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Uuid;

use Swoolefy\Library\Redis\RedisConnection;

final class FakeUuidRedis extends RedisConnection
{
    public int $evalCalls = 0;

    /**
     * @var array<int, mixed>
     */
    public array $evalQueue = [];

    /**
     * @var array<int, array>
     */
    public array $evalArgs = [];

    public function eval(...$args)
    {
        $this->evalCalls++;
        $this->evalArgs[] = $args;
        if ($this->evalQueue === []) {
            return [];
        }

        return array_shift($this->evalQueue);
    }
}
