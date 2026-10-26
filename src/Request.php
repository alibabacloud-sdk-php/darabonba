<?php

namespace AlibabaCloud\Dara;

use GuzzleHttp\Psr7\Request as PsrRequest;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * Class Request.
 */
class Request extends PsrRequest
{
    /**
     * @var string
     */
    public $protocol = 'https';

    /**
     * @var string
     */
    public $pathname = '/';

    /**
     * @var array
     */
    public $headers = [];

    /**
     * @var array
     */
    public $query = [];

    /**
     * @var string
     */
    public $body;

    /**
     * @var int
     */
    public $port;

    public $method;

    public function __construct($method = 'GET', $uri = '', array $headers = [], $body = null, $version = '1.1')
    {
        parent::__construct($method, $uri, $headers, $body, $version);
        $this->method = $method;
    }

    /**
     * These fields are compatible if you define other fields.
     * Mainly for compatibility situations where the code generator cannot generate set properties.
     *
     * @return PsrRequest
     */
    public function getPsrRequest()
    {
        $this->assertQuery($this->query);

        $request = clone $this;

        $uri = $request->getUri();
        if ($this->query) {
            $uri = $uri->withQuery(http_build_query($this->query));
        }

        if ($this->port) {
            $uri = $uri->withPort($this->port);
        }

        if ($this->protocol) {
            $uri = $uri->withScheme($this->protocol);
        }

        if ($this->pathname) {
            $uri = $uri->withPath($this->pathname);
        }

        if (isset($this->headers['host']) && is_string($this->headers['host'])) {
            list($host, $port) = self::splitHostHeader($this->headers['host']);
            $uri = $uri->withHost($host);
            // psr7 2.13 rejects a host that still contains ":port".
            if ($port !== null && !$this->port) {
                $uri = $uri->withPort($port);
            }
        }

        $request = $request->withUri($uri);
        $request = $request->withMethod($this->method);

        if ('' !== $this->body && null !== $this->body) {
            if ($this->body instanceof StreamInterface) {
                $request = $request->withBody($this->body);
            } else {
                $body = $this->body;
                if (Helper::isBytes($this->body)) {
                    $body = Helper::toString($this->body);
                }
                if (\function_exists('\GuzzleHttp\Psr7\stream_for')) {
                    // @deprecated stream_for will be removed in guzzlehttp/psr7:2.0
                    $request = $request->withBody(\GuzzleHttp\Psr7\stream_for($body));
                } else {
                    $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor($body));
                }
            }
        }

        if ($this->headers) {
            foreach ($this->headers as $key => $value) {
                $request = $request->withHeader($key, $value);
            }
        }

        return $request;
    }

    /**
     * Separate a Host header into the host and an optional port.
     * "localhost:8000" and "[::1]:8000" are both valid Host values, but the
     * port is not part of the URI host.
     *
     * @param string $hostHeader
     *
     * @return array
     */
    private static function splitHostHeader($hostHeader)
    {
        if ($hostHeader !== '' && $hostHeader[0] === '[') {
            $end = strpos($hostHeader, ']');
            if ($end !== false) {
                $host = substr($hostHeader, 0, $end + 1);
                $rest = substr($hostHeader, $end + 1);
                if (strlen($rest) > 1 && $rest[0] === ':') {
                    return array($host, (int) substr($rest, 1));
                }

                return array($host, null);
            }
        }

        if (substr_count($hostHeader, ':') === 1) {
            $parts = explode(':', $hostHeader, 2);
            if ($parts[1] !== '' && ctype_digit($parts[1])) {
                return array($parts[0], (int) $parts[1]);
            }
        }

        return array($hostHeader, null);
    }

    /**
     * @param array $query
     */
    private function assertQuery($query)
    {
        if (!\is_array($query) && $query !== null) {
            throw new InvalidArgumentException('Query must be array.');
        }
    }
}
