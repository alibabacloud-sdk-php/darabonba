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
}
