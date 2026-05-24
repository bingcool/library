<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos;

use Swoolefy\Library\Nacos\Exception\NacosApiException;
use Swoolefy\Library\Nacos\Exception\NacosException;
use Swoolefy\Library\Nacos\Http\GuzzleTransport;
use Swoolefy\Library\Nacos\Http\HttpResponse;
use Swoolefy\Library\Nacos\Http\HttpStatus;
use Swoolefy\Library\Nacos\Http\RequestMethod;
use Swoolefy\Library\Nacos\Provider\Auth\AuthProvider;
use Swoolefy\Library\Nacos\Provider\BaseProvider;
use Swoolefy\Library\Nacos\Provider\Config\ConfigProvider;
use Swoolefy\Library\Nacos\Provider\Instance\InstanceProvider;
use Swoolefy\Library\Nacos\Provider\Ns\NamespaceProvider;
use Swoolefy\Library\Nacos\Provider\Operator\OperatorProvider;
use Swoolefy\Library\Nacos\Provider\Service\ServiceProvider;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Swoolefy\Util\Log;

/**
 * @property AuthProvider      $auth
 * @property ConfigProvider    $config
 * @property NamespaceProvider $namespace
 * @property InstanceProvider  $instance
 * @property ServiceProvider   $service
 * @property OperatorProvider  $operator
 */
class Client
{
    /** @var array<string, class-string<BaseProvider>> */
    protected array $providersConfig = [
        'auth' => AuthProvider::class,
        'config' => ConfigProvider::class,
        'namespace' => NamespaceProvider::class,
        'instance' => InstanceProvider::class,
        'service' => ServiceProvider::class,
        'operator' => OperatorProvider::class,
    ];

    /** @var array<string, BaseProvider> */
    protected array $providers = [];

    protected ClientConfig $clientConfig;

    protected GuzzleTransport $transport;

    protected ?Log $logger;

    public function __construct(ClientConfig $config, ?Log $logger = null)
    {
        $this->clientConfig = $config;
        $this->logger = $logger;
        $this->transport = new GuzzleTransport($config);
    }

    public function __get(string $name): mixed
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        if (!isset($this->providersConfig[$name])) {
            throw new NacosException(sprintf('Provider %s does not exists', $name));
        }

        return $this->providers[$name] = new $this->providersConfig[$name]($this);
    }

    public function getConfig(): ClientConfig
    {
        return $this->clientConfig;
    }

    public function getLogger(): Log
    {
        return $this->logger;
    }

    public function getTransport(): GuzzleTransport
    {
        return $this->transport;
    }

    /**
     * @param array<string, mixed>|object $params
     *
     * @return HttpResponse|BaseProvider|object
     *
     * @throws NacosApiException
     */
    public function request(
        string $path,
        array|object $params = [],
        string $method = RequestMethod::GET,
        array $headers = [],
        ?string $responseClass = null,
        bool $useAccessToken = true,
    ): mixed {
        $config = $this->getConfig();
        $params = (array) $params;

        $queryParams = RequestMethod::GET === $method ? $params : [];
        $bodyParams = RequestMethod::GET === $method ? [] : $params;

        $requestHeaders = $headers;

        if ($useAccessToken && '' !== $config->getUsername() && '' !== $config->getPassword()) {
            $accessToken = $this->auth->getAccessToken();
            $queryParams['accessToken'] = $accessToken;
            $requestHeaders['accessToken'] = $accessToken;
            if ($config->getAuthorizationBearer()) {
                $requestHeaders['Authorization'] = 'Bearer ' . $accessToken;
            }
        }

        $options = [
            RequestOptions::HEADERS => $requestHeaders,
        ];

        if ([] !== $queryParams) {
            $options[RequestOptions::QUERY] = $queryParams;
        }

        if ([] !== $bodyParams) {
            $options[RequestOptions::FORM_PARAMS] = $bodyParams;
        }

        try {
            $httpResponse = $this->transport->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            throw new NacosApiException(sprintf(
                'Request failed: %s. Request method[%s], path[%s], headers:[%s], params:[%s]',
                $e->getMessage(),
                $method,
                $path,
                json_encode($requestHeaders, \JSON_PRETTY_PRINT),
                json_encode($params, \JSON_PRETTY_PRINT),
            ), 0, $e);
        }
        
        if (HttpStatus::OK !== $httpResponse->getStatusCode()) {
            $body = $httpResponse->body();
            $result = json_decode($body, true);
            if (\is_array($result) && isset($result['message'], $result['status'])) {
                throw new NacosApiException($result['message'], (int) $result['status'], null, $httpResponse);
            }

            $statusCode = $httpResponse->getStatusCode();
            throw new NacosApiException(
                '' === $body ? HttpStatus::getReasonPhrase($statusCode) : $body,
                $statusCode,
                null,
                $httpResponse,
            );
        }

        if (null !== $responseClass) {
            return new $responseClass($httpResponse);
        }

        return $httpResponse;
    }

    public function reopen(): void
    {
        $this->transport->reopen();
    }

    public function close(): void
    {
        $this->transport->reopen();
    }

    public function buildUrl(string $path = ''): string
    {
        $config = $this->getConfig();

        return sprintf(
            '%s://%s:%d%s%s',
            $config->getSsl() ? 'https' : 'http',
            $config->getHost(),
            $config->getPort(),
            $config->getPrefix(),
            $path,
        );
    }
}
