<?php

namespace AlibabaCloud\Dara\Tests\Unit;

use AlibabaCloud\Dara\AbstractWebSocketHandler;
use AlibabaCloud\Dara\Dara;
use AlibabaCloud\Dara\DefaultWebSocketClient;
use AlibabaCloud\Dara\HttpConnectProxyConnector;
use AlibabaCloud\Dara\Request;
use AlibabaCloud\Dara\WebSocketUtil;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class Ipv4OnlyTest extends TestCase
{
    /** @var int[] */
    private $pids = [];

    /**
     * @after
     */
    protected function cleanup()
    {
        foreach ($this->pids as $pid) {
            shell_exec('kill ' . (int) $pid . ' > /dev/null 2>&1');
        }
        $this->pids = [];
        Dara::config([]);
    }

    public function testIsIpv4Only()
    {
        self::assertFalse(Dara::isIpv4Only([]));
        self::assertFalse(Dara::isIpv4Only(['ipv4Only' => null]));
        self::assertFalse(Dara::isIpv4Only(['ipv4Only' => false]));
        self::assertFalse(Dara::isIpv4Only(['ipv4Only' => 0]));
        self::assertFalse(Dara::isIpv4Only(['ipv4Only' => 'false']));
        self::assertFalse(Dara::isIpv4Only(['ipv4Only' => '']));
        self::assertTrue(Dara::isIpv4Only(['ipv4Only' => true]));
        self::assertTrue(Dara::isIpv4Only(['ipv4Only' => 1]));
        self::assertTrue(Dara::isIpv4Only(['ipv4Only' => 'true']));
    }

    public function testResolveConfig()
    {
        $method = new \ReflectionMethod(Dara::class, 'resolveConfig');
        $method->setAccessible(true);

        $default = $method->invoke(null, []);
        self::assertArrayNotHasKey('force_ip_resolve', $default);
        self::assertArrayNotHasKey('curl', $default);
        self::assertArrayNotHasKey('force_ip_resolve', $method->invoke(null, ['ipv4Only' => false]));

        $ipv4 = $method->invoke(null, ['ipv4Only' => true, 'connectTimeout' => 1000]);
        self::assertSame('v4', $ipv4['force_ip_resolve']);
        self::assertSame(4, $ipv4['timeout']);
    }

    public function testCurlHandleGetsIpResolveV4()
    {
        $resolve = new \ReflectionMethod(Dara::class, 'resolveConfig');
        $resolve->setAccessible(true);
        $apply = new \ReflectionMethod(CurlFactory::class, 'applyHandlerOptions');
        $apply->setAccessible(true);

        $curlOptions = function (array $runtime) use ($resolve, $apply) {
            $easy = new EasyHandle();
            $easy->request = new PsrRequest('GET', 'http://localhost/');
            $easy->options = $resolve->invoke(null, $runtime);
            $conf = [];
            $apply->invokeArgs(new CurlFactory(1), [$easy, &$conf]);

            return $conf;
        };

        self::assertArrayNotHasKey(\CURLOPT_IPRESOLVE, $curlOptions([]));
        self::assertArrayNotHasKey(\CURLOPT_IPRESOLVE, $curlOptions(['ipv4Only' => false]));
        $conf = $curlOptions(['ipv4Only' => true]);
        self::assertSame(\CURL_IPRESOLVE_V4, $conf[\CURLOPT_IPRESOLVE]);
    }

    public function testSendPassesOptionThroughUserHandler()
    {
        $captured = [];
        Dara::config(['handler' => HandlerStack::create(function ($request, array $options) use (&$captured) {
            $captured[] = $options;

            return new FulfilledPromise(new PsrResponse(200));
        })]);

        Dara::send($this->localRequest(80));
        Dara::send($this->localRequest(80), ['ipv4Only' => true]);
        Dara::sendAsync($this->localRequest(80), ['ipv4Only' => true])->wait();

        self::assertCount(3, $captured);
        self::assertArrayNotHasKey('force_ip_resolve', $captured[0]);
        self::assertSame('v4', $captured[1]['force_ip_resolve']);
        self::assertSame('v4', $captured[2]['force_ip_resolve']);
    }

    public function testIpv4OnlyFailsAgainstIpv6OnlyServer()
    {
        $port = $this->startHttpServer('[::1]');

        try {
            $res = Dara::send($this->localRequest($port), ['connectTimeout' => 2000]);
        } catch (ConnectException $e) {
            self::markTestSkipped('localhost does not resolve to ::1: ' . $e->getMessage());
        }
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('ok', (string) $res->getBody());

        try {
            Dara::send($this->localRequest($port), ['ipv4Only' => true, 'connectTimeout' => 2000]);
            self::fail('ipv4Only request must not reach an IPv6-only server');
        } catch (ConnectException $e) {
            self::assertSame(\CURLE_COULDNT_CONNECT, $e->getHandlerContext()['errno']);
        }

        try {
            Dara::sendAsync($this->localRequest($port), ['ipv4Only' => true, 'connectTimeout' => 2000])->wait();
            self::fail('ipv4Only request must not reach an IPv6-only server');
        } catch (ConnectException $e) {
            self::assertSame(\CURLE_COULDNT_CONNECT, $e->getHandlerContext()['errno']);
        }

        // dual-stack requests keep working after ipv4Only ones
        $res = Dara::send($this->localRequest($port), ['ipv4Only' => false, 'connectTimeout' => 2000]);
        self::assertSame(200, $res->getStatusCode());
    }

    public function testIpv4OnlySucceedsAgainstIpv4Server()
    {
        $port = $this->startHttpServer('127.0.0.1');

        $res = Dara::send($this->localRequest($port), ['ipv4Only' => true, 'connectTimeout' => 2000]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('ok', (string) $res->getBody());

        $res = Dara::send($this->localRequest($port), ['connectTimeout' => 2000]);
        self::assertSame(200, $res->getStatusCode());

        $default = $this->daraStatic('defaultHandler');
        $ipv4Only = $this->daraStatic('ipv4OnlyHandler');
        self::assertInstanceOf(HandlerStack::class, $default);
        self::assertInstanceOf(HandlerStack::class, $ipv4Only);
        self::assertNotSame($default, $ipv4Only);
    }

    public function testWebSocketIpv4OnlyFailsAgainstIpv6OnlyServer()
    {
        $port = $this->startWebSocketServer('[::1]');

        $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
        try {
            $client->connect($this->wsRequest('localhost', $port), $this->wsRuntime([]));
        } catch (Exception $e) {
            self::markTestSkipped('localhost does not resolve to ::1: ' . $e->getMessage());
        }
        self::assertTrue($client->isConnected());
        $client->disconnect();

        foreach (['localhost', '[::1]'] as $host) {
            $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
            $error = null;
            try {
                $client->connect($this->wsRequest($host, $port), $this->wsRuntime(['ipv4Only' => true]));
            } catch (Exception $e) {
                $error = $e;
            }
            self::assertNotNull($error, 'ipv4Only websocket must not reach an IPv6-only server via ' . $host);
            self::assertFalse($client->isConnected());
        }
    }

    public function testWebSocketIpv4OnlySucceedsAgainstIpv4Server()
    {
        $port = $this->startWebSocketServer('127.0.0.1');

        $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
        $client->connect($this->wsRequest('localhost', $port), $this->wsRuntime(['ipv4Only' => true]));
        self::assertTrue($client->isConnected());
        $client->disconnect();
    }

    public function testStreamIpv4OnlyResolvesHostWithPort()
    {
        $port = $this->startHttpServer('127.0.0.1');
        $runtime = ['ipv4Only' => true, 'stream' => true, 'connectTimeout' => 2000];

        $request = $this->hostHeaderRequest('localhost:' . $port);
        $request->pathname = '/sse';
        $res = Dara::send($request, $runtime);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame("data: ok\n\n", (string) $res->getBody());

        $res = Dara::sendAsync($request, $runtime)->wait();
        self::assertSame(200, $res->getStatusCode());
    }

    public function testStreamIpv4OnlyFailsAgainstIpv6OnlyServer()
    {
        $port = $this->startHttpServer('[::1]');
        $runtime = ['ipv4Only' => true, 'stream' => true, 'connectTimeout' => 2000];

        try {
            Dara::send($this->hostHeaderRequest('localhost:' . $port), $runtime);
            self::fail('ipv4Only stream request must not reach an IPv6-only server');
        } catch (ConnectException $e) {
            self::assertStringMissing('Could not resolve', $e->getMessage());
        }

        try {
            Dara::send($this->hostHeaderRequest('[::1]:' . $port), $runtime);
            self::fail('ipv4Only stream request must not dial an IPv6 literal');
        } catch (ConnectException $e) {
            self::assertStringHas('refusing to connect to IPv6 address ::1', $e->getMessage());
        }

        try {
            Dara::send($this->hostHeaderRequest('ipv4only-nonexistent.invalid'), $runtime);
            self::fail('unresolvable host must fail');
        } catch (ConnectException $e) {
            self::assertStringHas("could not resolve IPv4 address for host 'ipv4only-nonexistent.invalid'", $e->getMessage());
        }
    }

    public function testStreamIpv4OnlyRewritesRequestForHandler()
    {
        $captured = [];
        Dara::config(['handler' => HandlerStack::create(function ($request, array $options) use (&$captured) {
            $captured[] = [$request, $options];

            return new FulfilledPromise(new PsrResponse(200));
        })]);

        $https = $this->hostHeaderRequest('localhost:8443');
        $https->protocol = 'https';
        Dara::send($https, ['ipv4Only' => true, 'stream' => true]);
        list($request, $options) = $captured[0];
        self::assertSame('127.0.0.1:8443', $request->getUri()->getHost());
        self::assertSame('localhost:8443', $request->getHeaderLine('Host'));
        self::assertSame('localhost', $options['stream_context']['ssl']['peer_name']);
        self::assertArrayNotHasKey('force_ip_resolve', $options);

        // IPv4 literals and proxied requests are left alone
        Dara::send($this->hostHeaderRequest('127.0.0.1:80'), ['ipv4Only' => true, 'stream' => true]);
        Dara::send($this->hostHeaderRequest('localhost'), [
            'ipv4Only' => true,
            'stream' => true,
            'httpProxy' => 'http://proxy.test:3128',
        ]);
        self::assertSame('127.0.0.1:80', $captured[1][0]->getUri()->getHost());
        self::assertSame('localhost', $captured[2][0]->getUri()->getHost());
        self::assertArrayNotHasKey('stream_context', $captured[2][1]);
        self::assertArrayNotHasKey('force_ip_resolve', $captured[2][1]);

        // non-stream requests keep using force_ip_resolve
        Dara::send($this->hostHeaderRequest('localhost'), ['ipv4Only' => true]);
        self::assertSame('localhost', $captured[3][0]->getUri()->getHost());
        self::assertSame('v4', $captured[3][1]['force_ip_resolve']);
    }

    public function testUsesProxy()
    {
        $method = new \ReflectionMethod(Dara::class, 'usesProxy');
        $method->setAccessible(true);
        $uses = function ($scheme, $host, array $options) use ($method) {
            return $method->invoke(null, $scheme, $host, $options);
        };

        self::assertFalse($uses('http', 'a.com', []));
        self::assertTrue($uses('http', 'a.com', ['proxy' => 'http://p:1']));
        self::assertFalse($uses('http', 'a.com', ['proxy' => '']));
        self::assertFalse($uses('http', 'a.com', ['proxy' => ['https' => 'http://p:1']]));
        self::assertTrue($uses('https', 'a.com', ['proxy' => ['https' => 'http://p:1']]));
        $proxy = ['http' => 'http://p:1'];
        self::assertFalse($uses('http', 'a.com', ['proxy' => $proxy + ['no' => '*']]));
        self::assertFalse($uses('http', 'a.com', ['proxy' => $proxy + ['no' => 'b.com, a.com']]));
        self::assertFalse($uses('http', 'x.a.com', ['proxy' => $proxy + ['no' => ['.a.com']]]));
        self::assertFalse($uses('http', 'x.a.com', ['proxy' => $proxy + ['no' => 'a.com']]));
        self::assertTrue($uses('http', 'xa.com', ['proxy' => $proxy + ['no' => 'a.com']]));
        self::assertTrue($uses('http', 'a.com', ['proxy' => $proxy + ['no' => ',']]));
    }

    public function testWebSocketThroughHttpProxy()
    {
        $wsPort = $this->startWebSocketServer('127.0.0.1');
        list($proxyPort, $log) = $this->startProxy('');

        foreach ([[], ['ipv4Only' => true]] as $extra) {
            $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
            $client->connect($this->wsRequest('fixture.test', $wsPort), $this->wsRuntime($extra + [
                'httpProxy' => 'http://127.0.0.1:' . $proxyPort,
            ]));
            self::assertTrue($client->isConnected());
            $client->disconnect();
        }
        self::assertSame(
            str_repeat('CONNECT fixture.test:' . $wsPort . " HTTP/1.1\n", 2),
            file_get_contents($log)
        );

        $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
        try {
            $client->connect($this->wsRequest('[::1]', $wsPort), $this->wsRuntime([
                'ipv4Only' => true,
                'httpProxy' => 'http://127.0.0.1:' . $proxyPort,
            ]));
            self::fail('ipv4Only must reject an IPv6 literal target');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('refusing to connect to IPv6 address', $e->getMessage());
        }
        self::assertFalse($client->isConnected());

        $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
        try {
            $client->connect($this->wsRequest('fixture.test', $wsPort), $this->wsRuntime([
                'ipv4Only' => true,
                'httpProxy' => 'http://[::1]:' . $proxyPort,
            ]));
            self::fail('ipv4Only must reject an IPv6-only HTTP proxy');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('refusing to connect to IPv6 proxy address', $e->getMessage());
        }
        self::assertFalse($client->isConnected());
    }

    public function testPinProxyUrlToIpv4()
    {
        $method = new \ReflectionMethod(DefaultWebSocketClient::class, 'pinProxyUrlToIpv4');
        $method->setAccessible(true);
        self::assertSame('http://127.0.0.1:8080', $method->invoke(null, 'http://127.0.0.1:8080'));
        self::assertSame('http://user:p%40ss@127.0.0.1:8080', $method->invoke(null, 'http://user:p%40ss@127.0.0.1:8080'));
        self::assertSame('http://127.0.0.1:1', $method->invoke(null, '127.0.0.1:1'));
        $pinned = $method->invoke(null, 'http://localhost:8080/path?x=1');
        self::assertStringStartsWith('http://127.0.0.1:8080/path?x=1', $pinned);
        try {
            $method->invoke(null, 'http://[::1]:8080');
            self::fail('expected IPv6 proxy host to be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('IPv6 proxy address', $e->getMessage());
        }
        try {
            $method->invoke(null, 'http:///');
            self::fail('expected invalid proxy URL');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('Invalid proxy URL', $e->getMessage());
        }
        try {
            $method->invoke(null, 'http://ipv4only-no-such-host.invalid:8080');
            self::fail('expected unresolvable proxy host');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('could not resolve IPv4 address for proxy host', $e->getMessage());
        }
    }

    public function testWebSocketHttpProxyAuthAndFailures()
    {
        $wsPort = $this->startWebSocketServer('127.0.0.1');
        list($authPort) = $this->startProxy('auth:user:p@ss');
        list($closePort) = $this->startProxy('close');

        $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
        $client->connect($this->wsRequest('fixture.test', $wsPort), $this->wsRuntime([
            'httpProxy' => 'http://user:p%40ss@127.0.0.1:' . $authPort,
        ]));
        self::assertTrue($client->isConnected());
        $client->disconnect();

        $failures = [
            'http://127.0.0.1:' . $authPort => 'Proxy refused CONNECT to fixture.test:' . $wsPort . ': HTTP/1.1 407',
            '127.0.0.1:' . $closePort => 'Proxy closed the connection',
        ];
        foreach ($failures as $proxy => $message) {
            $client = WebSocketUtil::newDefaultWebSocketClient(new AbstractWebSocketHandler());
            try {
                $client->connect($this->wsRequest('fixture.test', $wsPort), $this->wsRuntime(['httpProxy' => $proxy]));
                self::fail('expected proxy failure for ' . $proxy);
            } catch (Exception $e) {
                self::assertStringHas($message, $this->messages($e));
            }
            self::assertFalse($client->isConnected());
        }
    }

    public function testHttpConnectProxyConnectorRejectsInvalidInput()
    {
        $inner = new \React\Socket\Connector(\React\EventLoop\Factory::create());
        try {
            new HttpConnectProxyConnector('http://', $inner);
            self::fail('expected invalid proxy URL');
        } catch (\InvalidArgumentException $e) {
            self::assertStringHas('Invalid proxy URL', $e->getMessage());
        }

        $error = null;
        (new HttpConnectProxyConnector('127.0.0.1:1', $inner))->connect('no-port')->then(null, function ($e) use (&$error) {
            $error = $e;
        });
        self::assertInstanceOf(\InvalidArgumentException::class, $error);
    }

    private static function assertStringHas($needle, $haystack)
    {
        self::assertTrue(false !== strpos($haystack, $needle), sprintf('"%s" not found in "%s"', $needle, $haystack));
    }

    private static function assertStringMissing($needle, $haystack)
    {
        self::assertTrue(false === strpos($haystack, $needle), sprintf('"%s" unexpectedly found in "%s"', $needle, $haystack));
    }

    private function messages(Exception $e)
    {
        $messages = '';
        for (; null !== $e; $e = $e->getPrevious()) {
            $messages .= $e->getMessage() . "\n";
        }

        return $messages;
    }

    private function startProxy($mode)
    {
        $port = $this->freePort('127.0.0.1');
        $log = tempnam(sys_get_temp_dir(), 'dara-proxy-');
        $this->spawn(sprintf(
            '%s %s %d %s %s',
            escapeshellarg($this->phpCli()),
            escapeshellarg(dirname(__DIR__) . '/Mock/ConnectProxyServer.php'),
            $port,
            escapeshellarg($log),
            escapeshellarg($mode)
        ), '127.0.0.1', $port);
        return [$port, $log];
    }

    private function hostHeaderRequest($host)
    {
        $request = new Request();
        $request->method = 'GET';
        $request->protocol = 'http';
        $request->headers['host'] = $host;
        $request->pathname = '/';

        return $request;
    }

    private function localRequest($port)
    {
        $request = new Request();
        $request->method = 'GET';
        $request->protocol = 'http';
        $request->headers['host'] = 'localhost';
        $request->port = $port;
        $request->pathname = '/';

        return $request;
    }

    private function wsRequest($host, $port)
    {
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => $host . ':' . $port];

        return $request;
    }

    private function wsRuntime(array $extra)
    {
        return array_merge([
            'connectTimeout' => 2000,
            'webSocketPingInterval' => 0,
            'webSocketEnableReconnect' => false,
        ], $extra);
    }

    private function daraStatic($name)
    {
        $property = new \ReflectionProperty(Dara::class, $name);
        $property->setAccessible(true);

        return $property->getValue();
    }

    /**
     * Picks a port that is free on both 127.0.0.1 and [::1], so a server bound
     * to one family is guaranteed unreachable through the other.
     */
    private function freePort($bindHost)
    {
        if ('[::1]' === $bindHost) {
            $probe = @stream_socket_server('tcp://[::1]:0');
            if (false === $probe) {
                self::markTestSkipped('IPv6 loopback is not available');
            }
            fclose($probe);
        }
        for ($i = 0; $i < 20; ++$i) {
            $port = mt_rand(20000, 40000);
            $v4 = @stream_socket_server('tcp://127.0.0.1:' . $port);
            $v6 = @stream_socket_server('tcp://[::1]:' . $port);
            $free = false !== $v4 && ('[::1]' !== $bindHost || false !== $v6);
            if (false !== $v4) {
                fclose($v4);
            }
            if (false !== $v6) {
                fclose($v6);
            }
            if ($free) {
                return $port;
            }
        }
        self::markTestSkipped('no free local port found');
    }

    private function startHttpServer($bindHost)
    {
        $port = $this->freePort($bindHost);
        $router = dirname(__DIR__) . '/Mock/HttpOkServer.php';
        $this->spawn(sprintf(
            '%s -S %s %s',
            escapeshellarg($this->phpCli()),
            escapeshellarg($bindHost . ':' . $port),
            escapeshellarg($router)
        ), $bindHost, $port);

        return $port;
    }

    private function startWebSocketServer($bindHost)
    {
        $port = $this->freePort($bindHost);
        $server = dirname(__DIR__) . '/Mock/WebSocketServer.php';
        $this->spawn(sprintf(
            '%s %s %d %s',
            escapeshellarg($this->phpCli()),
            escapeshellarg($server),
            $port,
            escapeshellarg($bindHost)
        ), $bindHost, $port);

        return $port;
    }

    private function phpCli()
    {
        // under phpdbg PHP_BINARY is phpdbg, which has no built-in web server
        return 'cli' === \PHP_SAPI ? \PHP_BINARY : 'php';
    }

    private function spawn($command, $bindHost, $port)
    {
        $this->pids[] = (int) trim(shell_exec($command . ' > /dev/null 2>&1 & echo $!'));
        for ($i = 0; $i < 50; ++$i) {
            $fp = @stream_socket_client('tcp://' . $bindHost . ':' . $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);

                return;
            }
            usleep(100000);
        }
        self::markTestSkipped('local server failed to start on ' . $bindHost . ':' . $port);
    }
}
