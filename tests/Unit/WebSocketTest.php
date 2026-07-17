<?php

namespace AlibabaCloud\Dara\Tests;

use AlibabaCloud\Dara\AbstractWebSocketHandler;
use AlibabaCloud\Dara\DefaultWebSocketClient;
use AlibabaCloud\Dara\Request;
use AlibabaCloud\Dara\WebSocketMessage;
use AlibabaCloud\Dara\WebSocketMessageType;
use AlibabaCloud\Dara\WebSocketSessionInfo;
use AlibabaCloud\Dara\WebSocketUtil;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MockWebSocketHandler extends AbstractWebSocketHandler
{
    public $connectedCalled = false;
    public $messageReceivedCount = 0;
    public $errorCount = 0;
    public $closedCalled = false;
    /** @var WebSocketMessage|null */
    public $lastMessage;

    public function afterConnectionEstablished($session)
    {
        $this->connectedCalled = true;
    }

    public function handleRawMessage($session, $message)
    {
        $this->messageReceivedCount++;
        $this->lastMessage = $message;
    }

    public function handleError($session, $err)
    {
        $this->errorCount++;
    }

    public function afterConnectionClosed($session, $code, $reason)
    {
        $this->closedCalled = true;
    }
}

class ErrorOnConnectHandler extends MockWebSocketHandler
{
    public function afterConnectionEstablished($session)
    {
        throw new Exception('test error on connection');
    }
}

/**
 * @internal
 */
class WebSocketTest extends TestCase
{
    /** @var int */
    private $pid = 0;

    /** @var int */
    private $port = 18080;

    /**
     * @before
     */
    protected function startMockServer()
    {
        // Avoid `: void` / random_int() so PHP 5.6 / 7.0 CI matrix stays compatible.
        $this->port = 18080 + mt_rand(1, 1000);
        $server = dirname(__DIR__) . '/Mock/WebSocketServer.php';
        $command = sprintf('php %s %d > /dev/null 2>&1 & echo $!', escapeshellarg($server), $this->port);
        $output = shell_exec($command);
        $this->pid = (int) trim($output);
        $ready = false;
        for ($i = 0; $i < 50; ++$i) {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            throw new Exception('WebSocket mock server failed to start on port ' . $this->port);
        }
    }

    /**
     * @after
     */
    protected function stopMockServer()
    {
        if ($this->pid > 0) {
            shell_exec('kill ' . $this->pid);
            $this->pid = 0;
        }
    }

    public function testWebSocketClientCreation()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        self::assertNotNull($client);
        self::assertFalse($client->isConnected());

        $this->expectException(\InvalidArgumentException::class);
        WebSocketUtil::newDefaultWebSocketClient(null);
    }

    public function testWebSocketMessageTypes()
    {
        self::assertSame(0, WebSocketMessageType::TEXT);
        self::assertSame(1, WebSocketMessageType::BINARY);
    }

    public function testWebSocketSessionInfo()
    {
        $session = new WebSocketSessionInfo('test-session-123', '', microtime(true), '192.168.1.1:8080', '192.168.1.2:12345', []);
        self::assertSame('test-session-123', $session->sessionID);
        $session->attributes['key1'] = 'value1';
        $session->attributes['key2'] = 123;
        self::assertSame('value1', $session->attributes['key1']);
        self::assertSame(123, $session->attributes['key2']);
    }

    public function testMockHandler()
    {
        $handler = new MockWebSocketHandler();
        $session = new WebSocketSessionInfo('test', '', microtime(true), '', '', []);
        $handler->afterConnectionEstablished($session);
        self::assertTrue($handler->connectedCalled);

        $msg = new WebSocketMessage(WebSocketMessageType::TEXT, 'test message');
        $handler->handleRawMessage($session, $msg);
        $handler->handleRawMessage($session, $msg);
        self::assertSame(2, $handler->messageReceivedCount);
        self::assertSame('test message', $handler->lastMessage->payload);

        $handler->handleError($session, new Exception('deadline'));
        self::assertSame(1, $handler->errorCount);

        $handler->afterConnectionClosed($session, 1000, 'Normal');
        self::assertTrue($handler->closedCalled);
    }

    public function testConvertToWebSocketMessageType()
    {
        $tests = [
            ['text', WebSocketMessageType::TEXT],
            ['binary', WebSocketMessageType::BINARY],
            ['ping', WebSocketMessageType::PING],
            ['pong', WebSocketMessageType::PONG],
            ['close', WebSocketMessageType::CLOSE],
            ['unknown', WebSocketMessageType::BINARY],
        ];
        foreach ($tests as $test) {
            self::assertSame($test[1], WebSocketUtil::convertToWebSocketMessageType($test[0]));
        }
    }

    public function testDefaultWebSocketClientConnectSuccessful()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $runtimeObject = [
            'connectTimeout' => 5000,
            'readTimeout' => 30000,
            'webSocketPingInterval' => 0,
            'webSocketEnableReconnect' => false,
        ];

        $response = $client->connect($request, $runtimeObject);
        self::assertNotNull($response);
        self::assertTrue($client->isConnected());
        self::assertTrue($handler->connectedCalled);
        self::assertNotNull($client->getSessionInfo());
        self::assertNotEmpty($client->getSessionInfo()->sessionID);
        $client->disconnect();
    }

    public function testDefaultWebSocketClientInvalidUrl()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->headers = [];

        $this->expectException(InvalidArgumentException::class);
        $client->connect($request, ['connectTimeout' => 5000]);
    }

    public function testDefaultWebSocketClientConnectionTimeout()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:59999'];

        $runtimeObject = [
            'connectTimeout' => 100,
            'webSocketHandshakeTimeout' => 100,
        ];

        $this->expectException(\Exception::class);
        $client->connect($request, $runtimeObject);
        self::assertFalse($handler->connectedCalled);
    }

    public function testDefaultWebSocketClientHandlerErrorOnConnect()
    {
        $handler = new ErrorOnConnectHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $this->expectException(\Exception::class);
        $client->connect($request, ['connectTimeout' => 5000, 'webSocketPingInterval' => 0]);
    }

    public function testWebSocketReconnectWhenAlreadyConnected()
    {
        $handler = new MockWebSocketHandler();
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $runtimeObject = [
            'webSocketEnableReconnect' => true,
            'webSocketMaxReconnectTimes' => 3,
            'webSocketReconnectInterval' => 1000,
            'webSocketHandshakeTimeout' => 5000,
            'webSocketPingInterval' => 0,
            'webSocketHandler' => $handler,
            'connectTimeout' => 5000,
        ];

        list($client) = WebSocketUtil::newWebSocketClientAndConnect($request, $runtimeObject);
        self::assertTrue($client->isConnected());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already connected');
        $client->reconnect();
        $client->close();
    }

    public function testDefaultWebSocketClientReceivesMessagesInMainProcess()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $runtimeObject = [
            'connectTimeout' => 5000,
            'readTimeout' => 30000,
            'webSocketPingInterval' => 0,
            'webSocketEnableReconnect' => false,
        ];

        $client->connect($request, $runtimeObject);
        self::assertTrue($client->isConnected());

        $client->sendText('hello websocket');
        $client->pump(1000);

        self::assertSame(1, $handler->messageReceivedCount);
        self::assertSame('hello websocket', $handler->lastMessage->payload);
        $client->disconnect();
    }

    public function testSendBinaryAndClose()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = [
            'host' => '127.0.0.1:' . $this->port,
            'X-Custom' => '1',
        ];

        $client->connect($request, [
            'connectTimeout' => 5000,
            'webSocketPingInterval' => 0,
            'webSocketEnableReconnect' => false,
        ]);
        self::assertTrue($client->isConnected());
        $client->sendBinary('bin-data');
        $client->pump(500);
        self::assertGreaterThanOrEqual(1, $handler->messageReceivedCount);
        $client->close();
        self::assertFalse($client->isConnected());
    }

    public function testSendTextWhenDisconnected()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $this->expectException(Exception::class);
        $client->sendText('nope');
    }

    public function testConnectNullArgs()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        try {
            $client->connect(null, []);
            self::fail('expected');
        } catch (InvalidArgumentException $e) {
            self::assertTrue(true);
        }
        try {
            $client->connect(new Request(), null);
            self::fail('expected');
        } catch (InvalidArgumentException $e) {
            self::assertTrue(true);
        }
    }

    public function testConnectTlsProxyConfigPaths()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'wss';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $runtime = [
            'connectTimeout' => 200,
            'webSocketHandshakeTimeout' => 200,
            'ignoreSSL' => true,
            'ca' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'key' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'httpsProxy' => 'http://user:pass@127.0.0.1:1',
            'webSocketPingInterval' => 0,
            'webSocketEnableReconnect' => false,
        ];
        try {
            $client->connect($request, $runtime);
            self::fail('expected connection failure');
        } catch (Exception $e) {
            self::assertTrue(true);
        }
    }

    public function testConnectHttpProxyAndNoProxyAndSocks()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        try {
            $client->connect($request, [
                'connectTimeout' => 200,
                'httpProxy' => 'http://user:secret@127.0.0.1:1',
                'webSocketPingInterval' => 0,
            ]);
        } catch (Exception $e) {
            self::assertTrue(true);
        }

        $client2 = WebSocketUtil::newDefaultWebSocketClient($handler);
        try {
            $client2->connect($request, [
                'connectTimeout' => 200,
                'noProxy' => '127.0.0.1',
                'httpProxy' => 'http://127.0.0.1:1',
                'webSocketPingInterval' => 0,
            ]);
            // may succeed because noProxy skips proxy and server is up
            if ($client2->isConnected()) {
                $client2->disconnect();
            }
        } catch (Exception $e) {
            self::assertTrue(true);
        }

        $client3 = WebSocketUtil::newDefaultWebSocketClient($handler);
        try {
            $client3->connect($request, [
                'connectTimeout' => 2000,
                'socks5Proxy' => 'socks5://127.0.0.1:1',
                'webSocketPingInterval' => 0,
            ]);
            if ($client3->isConnected()) {
                $client3->disconnect();
            }
        } catch (Exception $e) {
            // expected when socks proxy is unavailable
        }
        self::assertTrue(true);
    }

    public function testReconnectAfterDisconnectAndPing()
    {
        $handler = new MockWebSocketHandler();
        $client = WebSocketUtil::newDefaultWebSocketClient($handler);
        $request = new Request();
        $request->protocol = 'ws';
        $request->pathname = '/';
        $request->headers = ['host' => '127.0.0.1:' . $this->port];

        $runtime = [
            'connectTimeout' => 5000,
            'webSocketPingInterval' => 50,
            'webSocketPongTimeout' => 200,
            'webSocketEnableReconnect' => true,
            'webSocketMaxReconnectTimes' => 2,
            'webSocketReconnectInterval' => 50,
        ];
        $client->connect($request, $runtime);
        self::assertTrue($client->isConnected());
        $client->pump(150);
        $client->disconnect();
        self::assertFalse($client->isConnected());

        // reconnect after clean disconnect
        $client->reconnect();
        self::assertTrue($client->isConnected());
        $client->reconnectGracefully();
        $client->close();
    }

}
