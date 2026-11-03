<?php

namespace AlibabaCloud\Dara;

use Adbar\Dot;
use AlibabaCloud\Dara\Exception\DaraException;
use AlibabaCloud\Dara\RetryPolicy\RetryOptions;
use AlibabaCloud\Dara\RetryPolicy\RetryPolicyContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;



/**
 * Class Dara.
 */
class Dara
{
    /**
     * @var array
     */
    private static $config = [];

    /**
     * @var HandlerStack|null
     */
    private static $defaultHandler;

    /**
     * Separate handler (and therefore curl connection pool) for ipv4Only requests,
     * so IPv4-only and dual-stack requests never share connections.
     *
     * @var HandlerStack|null
     */
    private static $ipv4OnlyHandler;

    const MAX_DELAY_TIME = 120 * 1000;
    const MIN_DELAY_TIME = 100;

    public static function config(array $config)
    {
        self::$config = $config;
    }

    /**
     * @throws GuzzleException
     *
     * @return Response
     */
    public static function send(Request $request, array $config = [])
    {
        if (method_exists($request, 'getPsrRequest')) {
            $request = $request->getPsrRequest();
        }

        $client = self::clientFor($config);
        list($request, $config) = self::prepare($request, $config);
        $res = $client->send(
            $request,
            $config
        );

        return new Response($res);
    }

    /**
     * @return PromiseInterface
     */
    public static function sendAsync(RequestInterface $request, array $config = [])
    {
        if (method_exists($request, 'getPsrRequest')) {
            $request = $request->getPsrRequest();
        }

        $client = self::clientFor($config);
        list($request, $config) = self::prepare($request, $config);

        return $client->sendAsync(
            $request,
            $config
        );
    }

    /**
     * @return Client
     */
    public static function client(array $config = [])
    {
        if (isset(self::$config['handler'])) {
            $stack = self::$config['handler'];
        } else {
            $stack = self::createHandler();
            self::$defaultHandler = $stack;
        }

        self::$config['handler'] = $stack;

        if (!isset(self::$config['on_stats'])) {
            self::$config['on_stats'] = function (TransferStats $stats) {
                Response::$info = $stats->getHandlerStats();
            };
        }

        $new_config = Helper::merge([self::$config, $config]);
        return new Client($new_config);
    }

    /**
     * @param array $runtime runtime options (RuntimeOptions map)
     *
     * @return bool
     */
    public static function isIpv4Only(array $runtime)
    {
        if (!isset($runtime['ipv4Only'])) {
            return false;
        }
        $ipv4Only = $runtime['ipv4Only'];
        if (\is_string($ipv4Only)) {
            return filter_var($ipv4Only, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $ipv4Only;
    }

    /**
     * @return array [RequestInterface, array guzzle options]
     */
    private static function prepare(RequestInterface $request, array $runtime)
    {
        $options = self::resolveConfig($runtime);
        if (self::isIpv4Only($runtime)) {
            return self::applyIpv4OnlyConstraints($request, $options);
        }

        return [$request, $options];
    }

    /**
     * Rewrite a proxy URL so the hop uses an IPv4 address (A record or IPv4 literal).
     * Used by the React/WebSocket connector only. For https proxies the original hostname
     * is kept in ?hostname= so SNI / peer_name still match after the authority is pinned.
     * Guzzle/cURL must NOT use this — libcurl ignores ?hostname= for cert verification;
     * keep the logical proxy hostname and rely on CURLOPT_IPRESOLVE=V4 instead.
     *
     * @param string $proxyUrl
     *
     * @return string
     */
    public static function pinProxyUrlToIpv4($proxyUrl)
    {
        $prefixed = false === strpos($proxyUrl, '://') ? 'http://' . $proxyUrl : $proxyUrl;
        $parts = parse_url($prefixed);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid proxy URL: ' . $proxyUrl);
        }
        $originalHost = trim($parts['host'], '[]');
        $host = $originalHost;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('ipv4Only is enabled, refusing to connect to IPv6 proxy address ' . $parts['host']);
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips = gethostbynamel($host);
            if (false === $ips || empty($ips)) {
                throw new \InvalidArgumentException(sprintf(
                    "ipv4Only is enabled, could not resolve IPv4 address for proxy host '%s'",
                    $host
                ));
            }
            $host = $ips[0];
        }
        $auth = '';
        if (isset($parts['user'])) {
            $auth = $parts['user'];
            if (isset($parts['pass'])) {
                $auth .= ':' . $parts['pass'];
            }
            $auth .= '@';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $queryParams = [];
        if (isset($parts['query']) && '' !== $parts['query']) {
            parse_str($parts['query'], $queryParams);
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        // Preserve original hostname for HTTPS proxy TLS after pinning authority to an IP
        if ('https' === $scheme && $originalHost !== $host) {
            $queryParams['hostname'] = $originalHost;
        }
        $query = empty($queryParams) ? '' : '?' . http_build_query($queryParams);
        $schemePrefix = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';

        return $schemePrefix . $auth . $host . $port . $path . $query;
    }

    /**
     * Extract the bare host from a URI host (psr7 1.x may embed "host:port").
     *
     * @param string   $host
     * @param null|int $port
     *
     * @return array{0: string, 1: null|int}
     */
    private static function splitUriHostPort($host, $port = null)
    {
        if (preg_match('/^\[([^\]]*)\](?::(\d+))?$/', $host, $m)) {
            $host = $m[1];
            if (null === $port && isset($m[2]) && '' !== $m[2]) {
                $port = (int) $m[2];
            }
        } elseif (preg_match('/^([^:]*):(\d+)$/', $host, $m)) {
            $host = $m[1];
            if (null === $port) {
                $port = (int) $m[2];
            }
        }

        return [$host, $port];
    }

    /**
     * Reject an IPv6-literal proxy URL. Does not rewrite hostnames — cURL keeps the
     * logical name and forces A records via CURLOPT_IPRESOLVE.
     *
     * @param string $proxyUrl
     */
    private static function assertProxyHostNotIpv6Literal($proxyUrl)
    {
        $prefixed = false === strpos($proxyUrl, '://') ? 'http://' . $proxyUrl : $proxyUrl;
        $parts = parse_url($prefixed);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid proxy URL: ' . $proxyUrl);
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('ipv4Only is enabled, refusing to connect to IPv6 proxy address ' . $parts['host']);
        }
    }

    /**
     * Only touch the proxy that this request will actually use (scheme + noProxy).
     * Unused http/https entries are left alone so an unresolvable unused proxy cannot
     * fail the request. cURL paths keep the logical hostname for TLS verification.
     *
     * @param string $scheme
     * @param string $host
     *
     * @return array
     */
    private static function enforceSelectedProxyIpv4(array $options, $scheme, $host)
    {
        if (!isset($options['proxy']) || !self::usesProxy($scheme, $host, $options)) {
            return $options;
        }
        $proxy = $options['proxy'];
        if (\is_string($proxy) && '' !== $proxy) {
            self::assertProxyHostNotIpv6Literal($proxy);

            return $options;
        }
        if (!\is_array($proxy) || empty($proxy[$scheme]) || !\is_string($proxy[$scheme])) {
            return $options;
        }
        self::assertProxyHostNotIpv6Literal($proxy[$scheme]);

        return $options;
    }

    /**
     * ipv4Only for Guzzle/cURL: keep logical request URI and proxy hostnames so
     * redirects preserve Host/Authorization and HTTPS proxy certs still match.
     * Address-family forcing is CURLOPT_IPRESOLVE=V4 (force_ip_resolve). Reject
     * IPv6 literals on the target and on the selected proxy hop.
     * Applied on every redirect hop via ipv4OnlyStreamPinMiddleware when stream=true.
     *
     * @return array [RequestInterface, array guzzle options]
     */
    private static function applyIpv4OnlyConstraints(RequestInterface $request, array $options)
    {
        $uri = $request->getUri();
        list($host) = self::splitUriHostPort($uri->getHost(), $uri->getPort());

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new ConnectException(sprintf('ipv4Only is enabled, refusing to connect to IPv6 address %s', $host), $request);
        }

        $options = self::enforceSelectedProxyIpv4($options, $uri->getScheme(), $host);

        return [$request, $options];
    }

    /**
     * Runs beneath RedirectMiddleware so every hop rejects IPv6 literals under ipv4Only.
     *
     * @return callable
     */
    private static function ipv4OnlyStreamPinMiddleware()
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                if (!empty($options['_dara_ipv4_only'])) {
                    list($request, $options) = self::applyIpv4OnlyConstraints($request, $options);
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * @param string $scheme
     * @param string $host
     *
     * @return bool
     */
    private static function usesProxy($scheme, $host, array $options)
    {
        if (!isset($options['proxy'])) {
            return false;
        }
        $proxy = $options['proxy'];
        if (!\is_array($proxy)) {
            return !empty($proxy);
        }
        if (empty($proxy[$scheme])) {
            return false;
        }
        if (empty($proxy['no'])) {
            return true;
        }
        $noProxy = \is_array($proxy['no']) ? $proxy['no'] : explode(',', $proxy['no']);
        foreach ($noProxy as $area) {
            $area = trim($area);
            if ('*' === $area || $area === $host) {
                return false;
            }
            $suffix = '.' . ltrim($area, '.');
            if ('' !== $area && substr($host, -\strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dual-stack default handler (Guzzle choose_handler, may wrap StreamHandler for stream=true).
     *
     * @return HandlerStack
     */
    private static function createHandler()
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response) {
            return new Response($response);
        }));

        return $stack;
    }

    /**
     * ipv4Only handler: always CurlHandler (ext-curl is required). Guzzle's default
     * stack routes stream=true to StreamHandler, whose force_ip_resolve uses
     * dns_get_record (breaks host:port / etc/hosts). Rewriting the URI to an IP to
     * work around that breaks redirect Host/Authorization. CurlHandler honors
     * CURLOPT_IPRESOLVE=V4 with the logical URI and proxy hostname intact.
     *
     * @return HandlerStack
     */
    private static function createIpv4OnlyHandler()
    {
        $stack = HandlerStack::create(new CurlHandler());
        // push (not unshift): last-pushed is innermost, so RedirectMiddleware re-enters this
        // on every hop and IPv6-literal rejection applies to redirect targets too
        $stack->push(self::ipv4OnlyStreamPinMiddleware(), 'dara_ipv4_stream_pin');
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response) {
            return new Response($response);
        }));

        return $stack;
    }

    /**
     * @return Client
     */
    private static function clientFor(array $runtime)
    {
        $client = self::client();
        // a user-supplied handler (Dara::config(['handler' => ...])) is used as-is
        if (!self::isIpv4Only($runtime) || self::$config['handler'] !== self::$defaultHandler) {
            return $client;
        }
        if (null === self::$ipv4OnlyHandler) {
            self::$ipv4OnlyHandler = self::createIpv4OnlyHandler();
        }

        return self::client(['handler' => self::$ipv4OnlyHandler]);
    }

    /**
     * @param string              $method
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @throws GuzzleException
     *
     * @return ResponseInterface
     */
    public static function request($method, $uri, $options = [])
    {
        return self::client()->request($method, $uri, $options);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $options
     *
     * @throws GuzzleException
     *
     * @return string
     */
    public static function string($method, $uri, $options = [])
    {
        return (string) self::client()->request($method, $uri, $options)
            ->getBody();
    }

    /**
     * @param string              $method
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @return PromiseInterface
     */
    public static function requestAsync($method, $uri, $options = [])
    {
        return self::client()->requestAsync($method, $uri, $options);
    }

    /**
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @throws GuzzleException
     *
     * @return null|mixed
     */
    public static function getHeaders($uri, $options = [])
    {
        return self::request('HEAD', $uri, $options)->getHeaders();
    }

    /**
     * @param string|UriInterface $uri
     * @param string              $key
     * @param null|mixed          $default
     *
     * @throws GuzzleException
     *
     * @return null|mixed
     */
    public static function getHeader($uri, $key, $default = null)
    {
        $headers = self::getHeaders($uri);

        return isset($headers[$key][0]) ? $headers[$key][0] : $default;
    }

    /**
     * @param int   $retryTimes
     * @param float $now
     *
     * @return bool
     */
    public static function allowRetry(array $runtime, $retryTimes, $now)
    {
        unset($now);
        if (!isset($retryTimes) || null === $retryTimes || !\is_numeric($retryTimes)) {
            return false;
        }
        if ($retryTimes > 0 && (empty($runtime) || !isset($runtime['retryable']) || !$runtime['retryable'] || !isset($runtime['maxAttempts']))) {
            return false;
        }
        $maxAttempts = $runtime['maxAttempts'];
        $retry       = empty($maxAttempts) ? 0 : (int) $maxAttempts;

        return $retry >= $retryTimes;
    }

    /**
     * @param int $retryTimes
     *
     * @return int
     */
    public static function getBackoffTime(array $runtime, $retryTimes)
    {
        $backOffTime = 0;
        $policy      = isset($runtime['policy']) ? $runtime['policy'] : '';

        if (empty($policy) || 'no' == $policy) {
            return $backOffTime;
        }

        $period = isset($runtime['period']) ? $runtime['period'] : '';
        if (null !== $period && '' !== $period) {
            $backOffTime = (int) $period;
            if ($backOffTime <= 0) {
                return $retryTimes;
            }
        }

        return $backOffTime;
    }

    /**
     * Sleep for the given duration in milliseconds.
     * Aligns with getBackoffDelay() which returns milliseconds.
     *
     * @param int $time duration in milliseconds
     */
    public static function sleep($time)
    {
        $ms = (int) $time;
        if ($ms <= 0) {
            return;
        }
        // usleep() may not support >1s on some platforms; split into sleep + usleep
        $seconds = (int) ($ms / 1000);
        $micros = ($ms % 1000) * 1000;
        if ($seconds > 0) {
            sleep($seconds);
        }
        if ($micros > 0) {
            usleep($micros);
        }
    }

    public static function isRetryable($retry, $retryTimes = 0)
    {
        if ($retry instanceof DaraException) {
            return true;
        }
        if (\is_array($retry)) {
            $max = isset($retry['maxAttempts']) ? (int) ($retry['maxAttempts']) : 3;

            return $retryTimes <= $max;
        }

        return false;
    }


    /**
     * 
     * @param RetryOptions $options
     * @param RetryPolicyContext $optctxions
     * @return bool
     */
    public static function shouldRetry($options, $ctx) {
        if($ctx->getRetryCount() === 0) {
            return true;
        }

        if (!$options || !$options->getRetryable()) {
            return false;
        }
    
        $retriesAttempted = $ctx->getRetryCount();
        $ex = $ctx->getException();
        $conditions = $options->getNoRetryCondition();
    
        foreach ($conditions as $condition) {
            if (in_array($ex->getName(), $condition->getException()) || in_array($ex->getErrCode(), $condition->getErrorCode())) {
                return false;
            }
        }
    
        $conditions = $options->getRetryCondition();
        foreach ($conditions as $condition) {
            if (!in_array($ex->getName(), $condition->getException()) && !in_array($ex->getErrCode(), $condition->getErrorCode())) {
                continue;
            }
            if ($retriesAttempted >= $condition->getMaxAttempts()) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 
     * @param RetryOptions $options
     * @param RetryPolicyContext $optctxions
     * @return int
     */
    public static function getBackoffDelay($options, $ctx) {
        $ex = $ctx->getException();
        // Match exception by logical name (same as shouldRetry), not PHP class basename.
        // e.g. DaraRespException::getName() => 'ResponseError'
        $conditions = $options->getRetryCondition();
        foreach ($conditions as $condition) {

            if (!in_array($ex->getName(), $condition->getException()) && !in_array($ex->getErrCode(), $condition->getErrorCode())) {
                continue;
            }
    
            $maxDelay = $condition->getMaxDelay() ?: self::MAX_DELAY_TIME;
            $retryAfter = method_exists($ex, 'getRetryAfter') ? $ex->getRetryAfter() : null;
    
            if ($retryAfter !== null) {
                return min($retryAfter, $maxDelay);
            }
            
            
            $backoff = $condition->getBackoff();
            if (!isset($backoff) || null === $backoff) {
                return self::MIN_DELAY_TIME;
            }
    
            return min($backoff->getDelayTime($ctx), $maxDelay);
        }
    
        return self::MIN_DELAY_TIME;
    }

    /**
     * @param mixed|Model[] ...$item
     *
     * @return mixed
     */
    public static function merge(...$item)
    {
        $tmp = [];
        $n   = 0;
        foreach ($item as $i) {
            if (\is_object($i)) {
                if ($i instanceof Model) {
                    $i = $i->toMap();
                } else {
                    $i = json_decode(json_encode($i), true);
                }
            }
            if (null === $i) {
                continue;
            }
            if (\is_array($i)) {
                $tmp[$n++] = $i;
            }
        }

        if (\count($tmp)) {
            return \call_user_func_array('array_merge', $tmp);
        }

        return [];
    }

    private static function resolveConfig(array $config = [])
    {
        $options = new Dot(['http_errors' => false]);
        if (isset($config['httpProxy']) && !empty($config['httpProxy'])) {
            $options->set('proxy.http', $config['httpProxy']);
        }
        if (isset($config['httpsProxy']) && !empty($config['httpsProxy'])) {
            $options->set('proxy.https', $config['httpsProxy']);
        }
        if (isset($config['noProxy']) && !empty($config['noProxy'])) {
            $options->set('proxy.no', $config['noProxy']);
        }
        if (isset($config['ignoreSSL']) && !empty($config['ignoreSSL'])) {
            $options->set('verify',!((bool)$config['ignoreSSL']));
        }
        if (isset($config['stream']) && !empty($config['stream'])) {
            $options->set(RequestOptions::STREAM, (bool)$config['stream']);
        }
        if (self::isIpv4Only($config)) {
            // curl handler maps this to CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
            $options->set(RequestOptions::FORCE_IP_RESOLVE, 'v4');
            $options->set('_dara_ipv4_only', true);
        }
        // readTimeout&connectTimeout unit is millisecond
        $read_timeout = isset($config['readTimeout']) && !empty($config['readTimeout']) ? (int) $config['readTimeout'] : 3000;
        $con_timeout  = isset($config['connectTimeout']) && !empty($config['connectTimeout']) ? (int) $config['connectTimeout'] : 3000;
        // timeout unit is second
        $options->set('timeout', ($read_timeout + $con_timeout) / 1000);

        // cURL: CURLOPT_IPRESOLVE=V4 covers target + selected proxy DNS.
        // Do not rewrite proxy hostnames to IPs here — that breaks HTTPS proxy cert
        // verification (libcurl ignores ?hostname=) and would also resolve unused
        // proxies before noProxy/scheme selection. WebSocket uses pinProxyUrlToIpv4.
        return $options->all();
    }
}
