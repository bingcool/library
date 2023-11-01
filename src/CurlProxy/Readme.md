使用:  
1、只需把代理的handler注入进来即可   
2、其他的按照GuzzleHttp原始操作即可   
3、请求时，自动注入trace-id,同时会在guzzle_curl.log记录一条请求参数日志   
4、响应时，自动在guzzle_curl.log记录一条返回数据的日志    

```
 $client = new \GuzzleHttp\Client([
            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(), // 只需把handler注入进来即可
            'base_uri' => "http://bing.uc.com/",
        ]);

        $response = $client->post('api/staff/test/echo1?name=bingcool',[
            'json' => [
                'class' => '一年级',
                'schoole' => '希望小学'
            ]
        ]);

        $result = $response->getBody()->getContents();
        $result = json_decode($result, true);
        var_dump($result);

```

