<?php

namespace AlibabaCloud\Dara\Tests\Unit;

use AlibabaCloud\Dara\Exception\DaraException;
use AlibabaCloud\Dara\Url;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    public function testPrivateLinkEndpointParts()
    {
        foreach (['http', 'https'] as $scheme) {
            $endpoint = $scheme . '://localhost:8080/prefix/';
            $url = Url::parse($endpoint);
            self::assertSame($scheme, $url->protocol());
            self::assertSame('localhost', $url->hostname());
            self::assertSame('8080', $url->port());
            self::assertSame('localhost:8080', $url->host());
            self::assertSame('/prefix/', $url->pathname());
            self::assertSame('/prefix/', $url->path());
            self::assertSame('', $url->auth());
            self::assertSame('', $url->search());
            self::assertSame('', $url->hash());
            self::assertSame($endpoint, $url->href());
        }
        self::assertSame('[::1]:8080', Url::parse('http://[::1]:8080')->host());
        self::assertSame('', Url::parse('https://localhost')->pathname());
        self::assertSame('https', Url::parse('HTTPS://localhost')->protocol());
        self::assertSame('', Url::parse('localhost/prefix')->host());
    }

    public function testValidationPartsArePreserved()
    {
        $url = Url::parse('http://user:pass@localhost/prefix?query=value#fragment');
        self::assertSame('user:pass', $url->auth());
        self::assertSame('?query=value', $url->search());
        self::assertSame('#fragment', $url->hash());
        self::assertSame('/prefix?query=value', $url->path());
        self::assertNotSame('', Url::parse('http://@localhost')->auth());
        self::assertSame('?', Url::parse('http://localhost?')->search());
        self::assertSame('#', Url::parse('http://localhost#')->hash());
    }

    public function testParseFailureUsesDaraException()
    {
        $this->expectException(DaraException::class);
        Url::parse('http://localhost:99999');
    }
}
