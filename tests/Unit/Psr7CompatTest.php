<?php

namespace AlibabaCloud\Dara\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Psr7CompatTest extends TestCase
{
    public function testStreamForRemainsCallable()
    {
        $this->assertTrue(function_exists('GuzzleHttp\\Psr7\\stream_for'));

        $stream = \GuzzleHttp\Psr7\stream_for('abc');
        $this->assertSame('abc', (string) $stream);
    }

    public function testPawl04FunctionsRemainCallable()
    {
        $this->assertTrue(function_exists('GuzzleHttp\\Psr7\\uri_for'));
        $this->assertTrue(function_exists('GuzzleHttp\\Psr7\\str'));
        $this->assertTrue(function_exists('GuzzleHttp\\Psr7\\parse_response'));

        $uri = \GuzzleHttp\Psr7\uri_for('https://example.com/a');
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('/a', $uri->getPath());

        $response = \GuzzleHttp\Psr7\parse_response("HTTP/1.1 204 No Content\r\n\r\n");
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotFalse(strpos(\GuzzleHttp\Psr7\str($response), '204'));
    }
}
