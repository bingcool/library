<?php

namespace Swoolefy\Library\Tests\Redis;

final class FakeNativeRedis
{
    public array $calls = [];

    public int $failTimes = 0;

    public ?\Throwable $failWith = null;

    public mixed $result = 'ok';

    public function __call(string $method, array $arguments)
    {
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        if ($this->failTimes > 0) {
            $this->failTimes--;
            throw $this->failWith ?? new \RedisException('read error on connection');
        }

        return $this->result;
    }

    public function close()
    {
        $this->calls[] = ['method' => 'close', 'args' => []];
        return true;
    }

    public function connect(...$args)
    {
        $this->calls[] = ['method' => 'connect', 'args' => $args];
        return true;
    }

    public function auth($password)
    {
        $this->calls[] = ['method' => 'auth', 'args' => [$password]];
        return true;
    }

    public function select($database)
    {
        $this->calls[] = ['method' => 'select', 'args' => [$database]];
        return true;
    }
}
