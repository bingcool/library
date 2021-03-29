<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\HttpClient;

/**
 * Interface HttpClientInterface
 * @package Common\Library\HttpClient
 */

interface HttpClientInterface
{
    /**
     * Sends a request to the server and returns the raw response.
     *
     * @param string $url     The endpoint to send the request to.
     * @param string $method  The request method.
     * @param string $body    The body of the request.
     * @param int    $timeOut The timeout in seconds for the request.
     *
     * @return RawResponse Raw response from the server.
     *
     * @throws \Common\Library\Exception\CurlException
     */
    public function send($url, $method, $body, int $timeOut);
}