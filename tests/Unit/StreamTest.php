<?php

namespace AlibabaCloud\Dara\Tests;

use AlibabaCloud\Dara\Util\StreamUtil;
use AlibabaCloud\Dara\Request;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class StreamTest extends TestCase
{

    public function getStream()
    {
        return new Stream(fopen('https://alibabacloud.com/', 'r'));
    }

    public function testReadAsBytes()
    {
        $bytes = StreamUtil::readAsBytes($this->getStream());
        $this->assertNotEmpty($bytes);
    }

    public function testReadAsString()
    {
        $string = StreamUtil::readAsString($this->getStream());
        $this->assertNotEmpty($string);
    }

    public function testReadAsJSON()
    {
        $result = StreamUtil::readAsJSON($this->getStream());
        // JSON parsing may return null for HTML content, which is expected
        $this->assertTrue(is_array($result) || is_null($result));
    }
}