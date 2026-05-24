<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Test;

use Swoolefy\Library\Nacos\Provider\Instance\InstanceProvider;
use Swoolefy\Library\Nacos\Provider\Instance\Model\RsInfo;

class InstanceTest extends BaseTest
{
    public const IP = '127.0.0.1';

    public const PORT = 1234;

    public const SERVICE_NAME = 'testInstance';

    public const RETURN_SERVICE_NAME = 'DEFAULT_GROUP@@testInstance';

    protected function getProvider(): InstanceProvider
    {
        return $this->getClient()->instance;
    }

    public function testRegister(): void
    {
        $this->assertTrue($this->getProvider()->register(self::IP, self::PORT, self::SERVICE_NAME, '', 1, false, true, '', '', '', true));
    }

    /**
     * @depends testRegister
     */
    public function testUpdate(): void
    {
        $this->assertTrue($this->getProvider()->update(self::IP, self::PORT, self::SERVICE_NAME, '', 1, true, true, '', '', '', true));
    }

    /**
     * @depends testRegister
     */
    public function testBeat(): void
    {
        $beat = new RsInfo();
        $beat->setIp(self::IP);
        $beat->setPort(self::PORT);
        $beat->setServiceName(self::SERVICE_NAME);
        $response = $this->getProvider()->beat(self::SERVICE_NAME, $beat);
        $this->assertGreaterThan(0, $response->getClientBeatInterval());
    }

    /**
     * @depends testRegister
     */
    public function testList(): void
    {
        $response = $this->getProvider()->list(self::SERVICE_NAME);
        $this->assertEquals(self::RETURN_SERVICE_NAME, $response->getName() ?: $response->getDom());
    }

    /**
     * @depends testRegister
     */
    public function testDetail(): void
    {
        try {
            $beat = new RsInfo();
            $beat->setIp(self::IP);
            $beat->setPort(self::PORT);
            $beat->setServiceName(self::SERVICE_NAME);
            $this->getProvider()->beat(self::SERVICE_NAME, $beat);
            $response = $this->getProvider()->detail(self::IP, self::PORT, self::SERVICE_NAME);
            $this->assertEquals(self::RETURN_SERVICE_NAME, $response->getService());
        } catch (\Swoolefy\Library\Nacos\Exception\NacosApiException $e) {
            if (str_contains($e->getMessage(), 'no ips found')) {
                $this->markTestSkipped('Ephemeral instance not visible for detail on this Nacos version');
            }
            throw $e;
        }
    }

    /**
     * @depends testRegister
     */
    public function testDeregister(): void
    {
        $this->assertTrue($this->getProvider()->deregister(self::IP, self::PORT, self::SERVICE_NAME));
    }
}
