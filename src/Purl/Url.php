<?php

declare(strict_types=1);

namespace Common\Library\Purl;

use Swoolefy\Http\RequestInput;

use function array_map;
use function explode;
use function ltrim;
use function preg_match_all;
use function sprintf;
use function strpos;

/**
 * Url is a simple OO class for manipulating Urls in PHP.
 *
 * @see https://github.com/jwage/purl
 *
 * @property string $scheme
 * @property string $host
 * @property int $port
 * @property string $user
 * @property string $pass
 * @property Path|string $path
 * @property Query|string $query
 * @property Fragment|string $fragment
 * @property string $canonical
 * @property string $resource
 */
class Url extends AbstractPart
{
    /** @var string|null The original url string. */
    private $url;

    /** @var ParserInterface|null */
    private $parser;

    /** @var mixed[] */
    protected $data = [
        'scheme'             => null,
        'host'               => null,
        'port'               => null,
        'user'               => null,
        'pass'               => null,
        'path'               => null,
        'query'              => null,
        'fragment'           => null,
        'publicSuffix'       => null,
        'registerableDomain' => null,
        'subdomain'          => null,
        'canonical'          => null,
        'resource'           => null,
    ];

    /** @var string[] */
    protected $partClassMap = [
        'path' => Path::class,
        'query' => Query::class,
        'fragment' => Fragment::class,
    ];

    public function __construct(?string $url = null, ?ParserInterface $parser = null)
    {
        $this->url    = $url;
        $this->parser = $parser;
    }

    public static function parse(string $url) : Url
    {
        return new self($url);
    }

    /**
     * @return Url[] $urls
     */
    public static function extract(string $string) : array
    {
        $regex = '/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(\/\S*)?/';

        preg_match_all($regex, $string, $matches);
        $urls = [];
        foreach ($matches[0] as $url) {
            $urls[] = self::parse($url);
        }

        return $urls;
    }

    public static function fromCurrent(RequestInput $request) : Url
    {
        $SERVER_PROTOCOL = $request->getServerParams('SERVER_PROTOCOL');
        $HTTP_HOST       = $request->getServerParams('HTTP_HOST');
        $SERVER_PORT     = $request->getServerParams('SERVER_PORT');

        if (str_contains($SERVER_PROTOCOL, 'HTTPS') || (!empty($SERVER_PORT) && $SERVER_PORT === 443)) {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        $hostItems = explode(':',$HTTP_HOST);
        $host      = $hostItems[0];
        $baseUrl   = sprintf('%s://%s', $scheme, $host);

        $url = new self($baseUrl);

        if (!empty($pathInfo = $request->getServerParams('PATH_INFO'))) {
            if (strpos($pathInfo, '?') !== false) {
                [$path, $query] = explode('?', $pathInfo, 2);
            } else {
                $path  = $pathInfo;
                $query = '';
            }

            $queryParams = $request->getQueryParams();
            $query       = http_build_query($queryParams);

            $url->set('path', $path);
            $url->set('query', $query);
        }

        // Only set port if different from default (80 or 443)
        if (!empty($SERVER_PORT)) {
            $port = $SERVER_PORT;
            if (($scheme === 'http' && $port !== 80) ||
                ($scheme === 'https' && $port !== 443)) {
                $url->set('port', $port);
            }
        }
        return $url;
    }

    public function getParser() : ParserInterface
    {
        if ($this->parser === null) {
            $this->parser = self::createDefaultParser();
        }

        return $this->parser;
    }

    public function setParser(ParserInterface $parser) : void
    {
        $this->parser = $parser;
    }

    /**
     * @param string|Url $url
     */
    public function join($url) : Url
    {
        $this->initialize();
        $parts = $this->getParser()->parseUrl($url);

        if ($this->data['scheme'] !== null) {
            $parts['scheme'] = $this->data['scheme'];
        }

        foreach ($parts as $key => $value) {
            if ($value === null) {
                continue;
            }

            $this->data[$key] = $value;
        }

        foreach ($this->data as $key => $value) {
            $this->data[$key] = $this->preparePartValue($key, $value);
        }

        return $this;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value) : AbstractPart
    {
        $this->initialize();

        $this->data[$key] = $this->preparePartValue($key, $value);

        return $this;
    }

    public function setPath(Path $path) : AbstractPart
    {
        $this->data['path'] = $path;

        return $this;
    }

    public function getPath() : Path
    {
        $this->initialize();

        return $this->data['path'];
    }

    public function setQuery(Query $query) : AbstractPart
    {
        $this->data['query'] = $query;

        return $this;
    }

    public function getQuery() : Query
    {
        $this->initialize();

        return $this->data['query'];
    }

    public function setFragment(Fragment $fragment) : AbstractPart
    {
        $this->data['fragment'] = $fragment;

        return $this;
    }

    public function getFragment() : Fragment
    {
        $this->initialize();

        return $this->data['fragment'];
    }

    public function getNetloc() : string
    {
        $this->initialize();

        return ($this->user !== null && $this->pass !== null ? $this->user . ($this->pass !== null ? ':' . $this->pass : '') . '@' : '') . $this->host . ($this->port !== null ? ':' . $this->port : '');
    }

    public function getUrl() : string
    {
        $this->initialize();

        $parts = array_map('strval', $this->data);

        if (! $this->isAbsolute()) {
            return self::httpBuildRelativeUrl($parts);
        }

        return self::httpBuildUrl($parts);
    }

    public function setUrl(string $url) : void
    {
        $this->initialized = false;
        $this->data        = [];
        $this->url         = $url;
    }

    public function isAbsolute() : bool
    {
        $this->initialize();

        return $this->scheme !== null && $this->host !== null;
    }

    public function __toString() : string
    {
        return $this->getUrl();
    }

    protected function doInitialize() : void
    {
        $parts = $this->getParser()->parseUrl($this->url);

        foreach ($parts as $k => $v) {
            if (isset($this->data[$k])) {
                continue;
            }

            $this->data[$k] = $v;
        }

        foreach ($this->data as $key => $value) {
            $this->data[$key] = $this->preparePartValue($key, $value);
        }
    }

    /**
     * @param string[] $parts
     */
    private static function httpBuildUrl(array $parts) : string
    {
        $relative = self::httpBuildRelativeUrl($parts);

        $pass = $parts['pass'] !== '' ? sprintf(':%s', $parts['pass']) : '';
        $auth = $parts['user'] !== '' ? sprintf('%s%s@', $parts['user'], $pass) : '';
        $port = $parts['port'] !== '' ? sprintf(':%d', $parts['port']) : '';

        return sprintf(
            '%s://%s%s%s%s',
            $parts['scheme'],
            $auth,
            $parts['host'],
            $port,
            $relative
        );
    }

    /**
     * @param string[] $parts
     */
    private static function httpBuildRelativeUrl(array $parts) : string
    {
        $parts['path'] = ltrim($parts['path'], '/');

        return sprintf(
            '/%s%s%s',
            $parts['path'],
            $parts['query'] !== '' ? '?' . $parts['query'] : '',
            $parts['fragment'] !== '' ? '#' . $parts['fragment'] : ''
        );
    }

    private static function createDefaultParser() : Parser
    {
        return new Parser();
    }
}
