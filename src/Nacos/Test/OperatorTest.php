<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Test;

use Common\Library\Nacos\Exception\NacosApiException;
use Common\Library\Nacos\Provider\Operator\OperatorProvider;

class OperatorTest extends BaseTest
{
    public const SERVICE_NAME = 'testService';

    protected function getProvider(): OperatorProvider
    {
        return $this->getClient()->operator;
    }

    public function testSwitches(): void
    {
        $this->getProvider()->switches();
        $this->assertTrue(true);
    }

    public function testUpdateSwitches(): void
    {
        $this->assertTrue($this->getProvider()->updateSwitches('test', 'test'));
    }

    public function testMetrics(): void
    {
        $this->getProvider()->metrics(true);
        $this->getProvider()->metrics(false);
        $this->assertTrue(true);
    }

    public function testServers(): void
    {
        try {
            $this->assertGreaterThan(0, $this->getProvider()->servers());
        } catch (NacosApiException $e) {
            if (str_contains($e->getMessage(), 'no such api')) {
                $this->markTestSkipped('operator/servers API not available on this Nacos version');
            }
            throw $e;
        }
    }
}
