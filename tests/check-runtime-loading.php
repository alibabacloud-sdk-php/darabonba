<?php

use AlibabaCloud\Dara\Internal\PromiseAwaiter;
use AlibabaCloud\Dara\WebSocketConnector;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

// Install the handler BEFORE Composer loads dependency function files. PHPUnit's
// bootstrap can otherwise miss deprecations emitted while loading vendor code.
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

require dirname(__DIR__) . '/vendor/autoload.php';
new ReflectionClass('AlibabaCloud\\Dara\\DefaultWebSocketClient');

$loop = Factory::create();
$server = new SocketServer('127.0.0.1:0', [], $loop);
$server->on('connection', function (ConnectionInterface $peer) {
    $request = '';
    $peer->on('data', function ($data) use ($peer, &$request) {
        $request .= $data;
        if (strpos($request, "\r\n\r\n") === false) {
            return;
        }
        $peer->removeAllListeners('data');
        if (!preg_match('/^Sec-WebSocket-Key: ([^\r\n]+)\r$/mi', $request, $matches)) {
            throw new RuntimeException('WebSocket upgrade request is missing its key');
        }
        $accept = base64_encode(sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $peer->write("HTTP/1.1 101 Switching Protocols\r\n"
            . "Connection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
    });
});

try {
    $connector = new WebSocketConnector($loop);
    $socket = PromiseAwaiter::await($connector('ws://' . substr($server->getAddress(), 6)), $loop, 5);
    // Sending a frame also loads the vendor class used after the handshake.
    $socket->send('deprecation check');
    $socket->close();
} finally {
    $server->close();
}
restore_error_handler();
echo "Runtime loaded and WebSocket handshake and frame completed without deprecations\n";
