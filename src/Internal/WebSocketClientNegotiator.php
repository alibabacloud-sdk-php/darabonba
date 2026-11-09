<?php

namespace AlibabaCloud\Dara\Internal;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Ratchet\RFC6455\Handshake\ResponseVerifier;

/**
 * Builds the RFC6455 0.4 client request with string header values. Its 0.4.1
 * negotiator passes an integer version to PSR-7, which Guzzle 2.11 deprecates.
 *
 * @internal
 */
final class WebSocketClientNegotiator
{
    public function generateRequest(UriInterface $uri)
    {
        return new Request('GET', $uri, [
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'User-Agent' => 'Ratchet',
        ]);
    }

    public function validateResponse(RequestInterface $request, ResponseInterface $response)
    {
        return (new ResponseVerifier())->verifyAll($request, $response);
    }
}
