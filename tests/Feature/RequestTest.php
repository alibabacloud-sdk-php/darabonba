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
    private function skipOnNetworkFailure($e)
    {
        $message = $e->getMessage();
        if (
            false !== stripos($message, 'timed out')
            || false !== stripos($message, 'Could not resolve')
            || false !== stripos($message, 'Failed to connect')
            || false !== stripos($message, 'Connection refused')
            || false !== stripos($message, 'cURL error')
            || false !== stripos($message, 'SSL')
        ) {
            $this->markTestSkipped('External network unavailable: ' . $message);
        }

        throw $e;
    }

    public function testRequest()
    {
        $request                  = new Request('get', '');
        $request->protocol        = 'https';
        $request->headers['host'] = 'www.alibabacloud.com';
        $request->query           = [
            'a' => 'a',
            'b' => 'b',
        ];
        try {
            $result = Dara::send($request, [
                'readTimeout' => 300000
            ]);
        } catch (\Exception $e) {
            $this->skipOnNetworkFailure($e);
        }
        self::assertEquals(200, $result->getStatusCode());
    }

    public function testString()
    {
        try {
            $string = Dara::string('get', 'http://www.alibabacloud.com/');
        } catch (\Exception $e) {
            $this->skipOnNetworkFailure($e);
        }
        self::assertNotEmpty($string);
    }

    public function testRequestWithBody()
    {
        $request                  = new Request();
        $request->method          = 'POST';
        $request->protocol        = 'https';
        $request->headers['host'] = 'www.alibabacloud.com';
        $request->body            = json_encode(['title' => 'foo', 'body' => 'bar', 'userId' => 1]);
        $request->pathname        = '/';
        $request->headers['content-type'] = 'application/json; charset=UTF-8';

        try {
            $res = Dara::send($request);
        } catch (\Exception $e) {
            $this->skipOnNetworkFailure($e);
        }

        // The site's reply to POST / differs by region (200 locally, 404 on GitHub runners).
        $statusCode = $res->getStatusCode();
        if ($statusCode >= 500) {
            $this->markTestSkipped('External server unavailable: HTTP ' . $statusCode);
        }
        $this->assertGreaterThanOrEqual(200, $statusCode);
        $this->assertLessThan(500, $statusCode);
    }
}
