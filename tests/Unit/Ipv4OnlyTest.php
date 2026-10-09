<?php

namespace AlibabaCloud\Dara\Tests;

use AlibabaCloud\Dara\AbstractWebSocketHandler;
use AlibabaCloud\Dara\Dara;
use AlibabaCloud\Dara\Models\RuntimeOptions;
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

    public function testRuntimeOptionsIpv4OnlyRoundTrip()
    {
        $default = new RuntimeOptions();
        self::assertNull($default->ipv4Only);
        self::assertArrayNotHasKey('ipv4Only', $default->toMap());
        self::assertNull(RuntimeOptions::fromMap([])->ipv4Only);

        $opts = RuntimeOptions::fromMap(['ipv4Only' => true, 'keepAlive' => true]);
        $opts->validate();
        self::assertTrue($opts->ipv4Only);
        self::assertSame(['keepAlive' => true, 'ipv4Only' => true], $opts->toMap());
        self::assertTrue(RuntimeOptions::fromMap($opts->toMap())->ipv4Only);

        $disabled = new RuntimeOptions(['ipv4Only' => false]);
        self::assertFalse($disabled->ipv4Only);
        self::assertSame(['ipv4Only' => false], $disabled->toMap());
        self::assertFalse(RuntimeOptions::fromMap($disabled->toMap())->ipv4Only);
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
        self::assertTrue(Dara::isIpv4Only(RuntimeOptions::fromMap(['ipv4Only' => true])->toMap()));
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
