<?php

namespace AlibabaCloud\Dara\Tests\Feature;

use AlibabaCloud\Dara\Request;
use AlibabaCloud\Dara\Dara;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class RequestTest extends TestCase
{
    public function testRequest()
    {
        $request                  = new Request('get', '');
        $request->protocol        = 'https';
        $request->headers['host'] = 'www.alibabacloud.com';
        $request->query           = [
            'a' => 'a',
            'b' => 'b',
        ];
        $result                   = Dara::send($request, [
            'readTimeout' => 300000
        ]);
        self::assertEquals(200, $result->getStatusCode());
    }

    public function testString()
    {
        $string = Dara::string('get', 'http://www.alibabacloud.com/');
        self::assertNotEmpty($string);
    }

    public function testRequestWithBody()
    {
        $request                  = new Request();
        $request->method          = 'POST';
        $request->protocol        = 'https';
        $request->headers['host'] = 'alibabacloud.com';
        $request->body            = json_encode(['title' => 'foo', 'body' => 'bar', 'userId' => 1]);
        $request->pathname        = '/posts';
        $request->headers['content-type'] = 'application/json; charset=UTF-8';

        $res  = Dara::send($request);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
