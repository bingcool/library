<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Common\Library\HttpClient;

use Common\Library\Exception\CurlException;
use Common\Library\Purl\Url;

/**
 * Class CurlHttpClient
 * @package Common\Library\HttpClient
 */
class CurlHttpClient implements HttpClientInterface
{
    /**
     * @var BaseCurl Procedural curl as object
     */
    protected $baseCurl;

    /**
     * @var array
     */
    protected $headers = [];

    /**
     * @var array The Curl options
     */
    protected $options = [];

    /**
     * @var string|bool The raw response from the server
     */
    protected $rawResponse;

    /**
     * @var string The client error message
     */
    protected $curlErrorMessage = '';

    /**
     * @var int The curl client error code
     */
    protected $curlErrorCode = 0;

    /**
     * @param BaseCurl|null Procedural curl as object
     */
    public function __construct(BaseCurl $Curl = null)
    {
        $this->baseCurl = $Curl ?: new BaseCurl();
    }

    /**
     * @return array
     */
    protected function getDefaultOptions()
    {
        return $options = [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true, // Return response as string
            CURLOPT_HEADER => false, // Enable header processing
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
    }

    /**
     * @return resource
     */
    public function getCurlHandler()
    {
        return $this->baseCurl->getCurlHandler();
    }

    /**
     * Sends a request to the server and returns the raw response.
     *
     * @param string $url The endpoint to send the request to.
     * @param string $method The request method.
     * @param string $body The body of the request.
     * @param int $connectTimeOut The timeout in seconds for the request.
     * @param int $timeOut The timeout in seconds for the request.
     *
     * @return RawResponse Raw response from the server.
     *
     * @throws CurlException
     */
    public function send(
        string $url,
        string $method,
        $body = null,
        int $connectTimeOut = 10,
        int $timeOut = 10
    )
    {
        $method = strtoupper($method);
        if ($connectTimeOut >= $timeOut) {
            $timeOut += 3;
        }
        $this->openConnection($url, $method, $body, $this->headers, $connectTimeOut, $timeOut);
        $this->sendRequest();
        $curlErrorCode = $this->baseCurl->errno();
        $this->curlErrorCode = $curlErrorCode;
        $curlErrorCode && $this->curlErrorMessage = $this->baseCurl->error();
        if ($curlErrorCode) {
            throw new CurlException($this->baseCurl->error(), $curlErrorCode);
        }
        // Separate the raw headers from the raw body
        list($rawHeaders, $rawBody) = $this->extractResponseHeadersAndBody();
        $info = $this->baseCurl->getInfo();

        $this->close();

        return new RawResponse($rawHeaders, $rawBody, $info);
    }

    /**
     * Opens a new curl connection.
     *
     * @param string $url The endpoint to send the request to.
     * @param string $method The request method.
     * @param string $body The body of the request.
     * @param array $headers The request headers.
     * @param int $connectTimeOut The timeout in seconds for the connect request.
     * @param int $readTimeOut The timeout in seconds for the request.
     */
    public function openConnection(
        string $url,
        string $method,
        $body,
        array $headers = [],
        int $connectTimeOut = 10,
        int $readTimeOut = 10
    )
    {
        if ($url) {
            $this->options[CURLOPT_URL] = $url;
        }

        if ($headers) {
            $this->options[CURLOPT_HTTPHEADER] = $this->compileRequestHeaders($headers);
        }

        if ($connectTimeOut) {
            $this->options[CURLOPT_CONNECTTIMEOUT] = $connectTimeOut;
        }

        if ($readTimeOut) {
            $this->options[CURLOPT_TIMEOUT] = $readTimeOut;
        }

        if ($method !== 'GET') {
            if (empty($body)) {
                throw new CurlException('Post Curl Body empty');
            }
            $this->options[CURLOPT_POSTFIELDS] = $body;
        }

        $this->baseCurl->init();

        $options = $this->options + $this->getDefaultOptions();

        if (isset($this->options[CURLOPT_NOBODY]) && (bool)$this->options[CURLOPT_NOBODY] === true) {
            $options[CURLOPT_HEADER] = $this->options[CURLOPT_HEADER] = true;
        }

        $this->baseCurl->setOptionArray($options);
    }

    /**
     * Send the request and get the raw response from curl
     */
    public function sendRequest()
    {
        $this->rawResponse = $this->baseCurl->exec();
    }

    /**
     * Compiles the request headers into a curl-friendly format.
     *
     * @param array $headers The request headers.
     *
     * @return array
     */
    public function compileRequestHeaders(array $headers)
    {
        $return = [];
        foreach ($headers as $key => $value) {
            $return[] = $key . ': ' . $value;
        }
        return $return;
    }

    /**
     * Extracts the headers and the body into a two-part array
     *
     * @return array
     */
    public function extractResponseHeadersAndBody()
    {
        $parts = explode("\r\n\r\n", $this->rawResponse);
        $rawBody = array_pop($parts);
        $rawHeaders = implode("\r\n\r\n", $parts);

        return [trim($rawHeaders), trim($rawBody)];
    }

    /**
     * @param string $url
     * @param array $params
     * @param int $connectTimeOut
     * @param int $timeOut
     * @return RawResponse
     * @throws CurlException
     */
    public function get(
        string $url,
        array $params = [],
        int $connectTimeOut = 10,
        int $timeOut = 10
    )
    {
        if (!empty($params)) {
            $url = $this->parseUrl($url, $params);
        }
        $this->options[CURLOPT_HTTPGET] = "GET";
        return $this->send($url, 'GET', '', $connectTimeOut, $timeOut);
    }

    /**
     * @param string $url
     * @param array $params
     * @param int $timeOut
     * @return RawResponse|bool
     * @throws CurlException
     */
    public function post(
        string $url,
        array $params,
        int $connectTimeOut = 10,
        int $timeOut = 10
    )
    {
        if (empty($params)) {
            return false;
        }
        $this->options[CURLOPT_POST] = 1;
        return $this->send($url, 'POST', $params, $connectTimeOut, $timeOut);
    }

    /**
     * @param string $url
     * @param array $params
     * @return string
     */
    public function parseUrl(string $url, array $params = [])
    {
        $uri = parse_url($url);
        if (is_array($params) && !empty($params)) {
            $queryString = http_build_query($params);
            if (isset($uri['query']) && !empty($uri['query'])) {
                $uri['query'] = $uri['query'] . '&' . $queryString;
            } else {
                $uri['query'] = $queryString;
            }
        }

        $newUrl = new Url();
        $baseUrl = sprintf('%s://%s', $uri['scheme'], $uri['host']);
        $newUrl->setUrl($baseUrl);

        if (isset($uri['user'])) {
            $newUrl->set('user', $uri['user']);
        }

        if (isset($uri['pass'])) {
            $newUrl->set('pass', $uri['pass']);
        }

        if (isset($uri['port'])) {
            $newUrl->set('port', $uri['port']);
        }

        if (isset($uri['path'])) {
            $newUrl->set('path', $uri['path']);
        }

        if (isset($uri['query'])) {
            $newUrl->set('query', $uri['query']);
        }

        if (isset($uri['fragment'])) {
            $newUrl->set('fragment', $uri['fragment']);
        }

        return $newUrl->getUrl();
    }

    /**
     * @param array $options
     */
    public function setOptionArray(array $options)
    {
        $this->options = $options + $this->options;
    }

    /**
     * @param array $headers
     */
    public function setHeaderArray(array $headers)
    {
        $this->headers = $headers + $this->headers;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @return int
     */
    public function getCurlErrorCode()
    {
        return $this->curlErrorCode;
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->curlErrorMessage;
    }

    /**
     * Closes an existing curl connection
     */
    public function close()
    {
        $this->baseCurl->close();
    }

}