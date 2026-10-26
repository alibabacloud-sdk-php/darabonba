<?php

namespace AlibabaCloud\Dara\Tests\Unit;

use AlibabaCloud\Dara\Request;
use PHPUnit\Framework\TestCase;

/**
 * Class RequestTest.
 *
 * @internal
 */
class RequestTest extends TestCase
{
    public static function testGetPsrRequest()
    {
        $request                  = new Request('get', '');
        $request->protocol        = 'https';
        $request->headers['host'] = 'www.alibaba.com';
        $psrRequest               = $request->getPsrRequest();
        self::assertEquals('https://www.alibaba.com/', (string) $psrRequest->getUri());
        self::assertInstanceOf(\GuzzleHttp\Psr7\Request::class, $psrRequest);
    }

    public function testHostHeaderPortIsNotPartOfTheHost()
    {
        $request = new Request('get', '');
        $request->protocol = 'http';
        $request->headers['host'] = 'localhost:8000';
        $request->pathname = '/sse';

        $uri = $request->getPsrRequest()->getUri();
        self::assertEquals('localhost', $uri->getHost());
        self::assertEquals(8000, $uri->getPort());
        self::assertEquals('http://localhost:8000/sse', (string) $uri);
    }

    public function testBracketedIpv6HostKeepsItsPort()
    {
        $request = new Request('get', '');
        $request->protocol = 'https';
        $request->headers['host'] = '[::1]:8443';

        $uri = $request->getPsrRequest()->getUri();
        self::assertEquals('[::1]', $uri->getHost());
        self::assertEquals(8443, $uri->getPort());
    }
}
