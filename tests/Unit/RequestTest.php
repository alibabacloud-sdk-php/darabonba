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

    public function testPsr7V2SplitsPortOutOfHostHeader()
    {
        if (\function_exists('\\GuzzleHttp\\Psr7\\stream_for')) {
            $this->markTestSkipped('guzzlehttp/psr7 1.x keeps the port inside the host');
        }

        $request = new Request('get', '');
        $request->protocol = 'https';
        $request->headers['host'] = 'alidns.aliyuncs.com:443';

        $psrRequest = $request->getPsrRequest();
        $uri = $psrRequest->getUri();
        self::assertEquals('alidns.aliyuncs.com', $uri->getHost());
        self::assertNull($uri->getPort());
        self::assertEquals('https://alidns.aliyuncs.com/', (string) $uri);
        self::assertEquals('alidns.aliyuncs.com:443', $psrRequest->getHeaderLine('host'));

        $customPort = new Request('get', '');
        $customPort->protocol = 'https';
        $customPort->headers['host'] = 'alidns.aliyuncs.com:8443';
        $customUri = $customPort->getPsrRequest()->getUri();
        self::assertEquals('alidns.aliyuncs.com', $customUri->getHost());
        self::assertEquals(8443, $customUri->getPort());
    }

    public function testPsr7V2KeepsAnExplicitPort()
    {
        if (\function_exists('\\GuzzleHttp\\Psr7\\stream_for')) {
            $this->markTestSkipped('guzzlehttp/psr7 1.x keeps the port inside the host');
        }

        $request = new Request('get', '');
        $request->protocol = 'http';
        $request->port = 8443;
        $request->headers['host'] = 'example.com:443';

        $uri = $request->getPsrRequest()->getUri();
        self::assertEquals('example.com', $uri->getHost());
        self::assertEquals(8443, $uri->getPort());
    }

    public function testBracketedIpv6HostKeepsItsPortOnPsr7V2()
    {
        if (\function_exists('\\GuzzleHttp\\Psr7\\stream_for')) {
            $this->markTestSkipped('guzzlehttp/psr7 1.x keeps the port inside the host');
        }

        $request = new Request('get', '');
        $request->protocol = 'https';
        $request->headers['host'] = '[::1]:8443';

        $uri = $request->getPsrRequest()->getUri();
        self::assertEquals('[::1]', $uri->getHost());
        self::assertEquals(8443, $uri->getPort());
    }
}
