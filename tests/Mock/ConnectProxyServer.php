<?php

require __DIR__ . '/../../vendor/autoload.php';

// Minimal HTTP CONNECT proxy for tests.
// usage: ConnectProxyServer.php <port> <logFile> [mode]
//   mode "auth:<user:pass>" requires Basic proxy auth; mode "close" drops every client.
// Targets ending in ".test" are only resolvable here (mapped to 127.0.0.1), proving
// the client handed the host name to the proxy instead of resolving it.

$port = (int) $argv[1];
$logFile = $argv[2];
$mode = isset($argv[3]) ? $argv[3] : '';

$loop = React\EventLoop\Factory::create();
$server = new React\Socket\SocketServer('127.0.0.1:' . $port, [], $loop);
$connector = new React\Socket\Connector($loop);

$server->on('connection', function (React\Socket\ConnectionInterface $client) use ($connector, $logFile, $mode) {
    if ('close' === $mode) {
        $client->close();

        return;
    }
    $buffer = '';
    $client->on('data', $onData = function ($chunk) use (&$buffer, &$onData, $client, $connector, $logFile, $mode) {
        $buffer .= $chunk;
        $end = strpos($buffer, "\r\n\r\n");
        if (false === $end) {
            return;
        }
        $client->removeListener('data', $onData);
        $head = substr($buffer, 0, $end);
        $rest = substr($buffer, $end + 4);
        file_put_contents($logFile, strtok($head, "\r\n") . "\n", FILE_APPEND);

        if (0 === strpos($mode, 'auth:')
            && false === stripos($head, 'Proxy-Authorization: Basic ' . base64_encode(substr($mode, 5)))) {
            $client->end("HTTP/1.1 407 Proxy Authentication Required\r\n\r\n");

            return;
        }
        if (!preg_match('#^CONNECT (\S+):(\d+) #', $head, $m)) {
            $client->end("HTTP/1.1 400 Bad Request\r\n\r\n");

            return;
        }
        $host = '.test' === substr($m[1], -5) ? '127.0.0.1' : $m[1];
        $connector->connect($host . ':' . $m[2])->then(function ($upstream) use ($client, $rest) {
            $client->write("HTTP/1.1 200 Connection established\r\n\r\n");
            if ('' !== $rest) {
                $upstream->write($rest);
            }
            $client->pipe($upstream);
            $upstream->pipe($client);
        }, function () use ($client) {
            $client->end("HTTP/1.1 502 Bad Gateway\r\n\r\n");
        });
    });
});

$loop->run();
