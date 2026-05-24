<?php

namespace Swoolefy\Library\Tests\HttpClient;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Protobuf\Serializer;

class ClientTest extends TestCase
{
    public function testCurl()
    {
        $curl = new \Swoolefy\Library\HttpClient\CurlHttpClient();
        $curl->setOptionArray([
            // 设置返回响应头，默认false
            CURLOPT_HEADER => true
        ]);
        $result = $curl->get('https://www.baidu.com');

        var_dump($result->getRequestTotalTime());

    }
}