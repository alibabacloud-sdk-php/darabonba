<?php

namespace AlibabaCloud\Dara;

use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;

/**
 * Opens a TCP tunnel through an HTTP(S) proxy with CONNECT. The target host name is
 * sent to the proxy unresolved; TLS (wss) is layered on top by React's SecureConnector.
 * When the proxy URL itself uses https, the hop to the proxy is TLS (tls://) with
 * certificate verification; http proxies use plaintext tcp://.
 */
class HttpConnectProxyConnector implements ConnectorInterface
{
    /**
     * @var ConnectorInterface
     */
    private $connector;

    /**
     * @var string
     */
    private $proxyAddress;

    /**
     * @var string|null
     */
    private $proxyAuthorization;

    /**
     * @param string             $proxyUrl  e.g. http://user:pass@proxy:8080 or https://proxy:8443
     * @param ConnectorInterface $connector dials the proxy itself
     */
    public function __construct($proxyUrl, ConnectorInterface $connector)
    {
        $parts = parse_url(false === strpos($proxyUrl, '://') ? 'http://' . $proxyUrl : $proxyUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid proxy URL: ' . $proxyUrl);
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        $secure = ('https' === $scheme);
        if (!$secure && 'http' !== $scheme) {
            throw new \InvalidArgumentException('Unsupported proxy scheme: ' . $scheme);
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($secure ? 443 : 80);
        $host = $parts['host'];
        // React expects bracketed IPv6 literals in the authority
        if (false !== strpos($host, ':') && '[' !== $host[0]) {
            $host = '[' . $host . ']';
        }
        $address = ($secure ? 'tls://' : 'tcp://') . $host . ':' . $port;
        // When the hop was pinned to an IPv4 literal, keep the original hostname for SNI / peer_name
        if ($secure && isset($parts['query']) && '' !== $parts['query']) {
            $address .= '?' . $parts['query'];
        }
        $this->proxyAddress = $address;
        if (isset($parts['user'])) {
            $password = isset($parts['pass']) ? $parts['pass'] : '';
            $this->proxyAuthorization = 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($password));
        }
        $this->connector = $connector;
    }

    public function connect($uri)
    {
        $parts = parse_url(false === strpos($uri, '://') ? 'tcp://' . $uri : $uri);
        if (false === $parts || !isset($parts['host'], $parts['port'])) {
            return \React\Promise\reject(new \InvalidArgumentException('Invalid target URI: ' . $uri));
        }
        $target = $parts['host'] . ':' . $parts['port'];
        $authorization = $this->proxyAuthorization;

        return $this->connector->connect($this->proxyAddress)->then(
            function (ConnectionInterface $connection) use ($target, $authorization) {
                $deferred = new Deferred(function () use ($connection) {
                    $connection->close();
                });
                $buffer = '';
                $onData = null;
                $onClose = function () use ($deferred) {
                    $deferred->reject(new RuntimeException('Proxy closed the connection before CONNECT completed'));
                };
                $onData = function ($chunk) use (&$buffer, &$onData, $onClose, $connection, $deferred, $target) {
                    $buffer .= $chunk;
                    $end = strpos($buffer, "\r\n\r\n");
                    if (false === $end) {
                        return;
                    }
                    $connection->removeListener('data', $onData);
                    $connection->removeListener('close', $onClose);
                    if (!preg_match('#^HTTP/\d(?:\.\d)? 2\d\d\b#', $buffer)) {
                        $statusLine = strtok($buffer, "\r\n");
                        $connection->close();
                        $deferred->reject(new RuntimeException('Proxy refused CONNECT to ' . $target . ': ' . $statusLine));

                        return;
                    }
                    $deferred->resolve($connection);
                };
                $connection->on('data', $onData);
                $connection->on('close', $onClose);

                $head = 'CONNECT ' . $target . " HTTP/1.1\r\nHost: " . $target . "\r\n";
                if (null !== $authorization) {
                    $head .= 'Proxy-Authorization: ' . $authorization . "\r\n";
                }
                $connection->write($head . "\r\n");

                return $deferred->promise();
            }
        );
    }
}
